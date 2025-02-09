<?php
// login.php -- HotCRP login helpers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class LoginHelper {
    /** @var bool */
    const DEBUG = false;

    static function check_http_auth(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        assert($conf->opt("httpAuthLogin") !== null);

        // if user signed out of HTTP authentication, send a reauth request
        if ($qreq->has_gsession("reauth")) {
            $qreq->unset_gsession("reauth");
            header("HTTP/1.0 401 Unauthorized");
            if (is_string($conf->opt("httpAuthLogin"))) {
                header("WWW-Authenticate: " . $conf->opt("httpAuthLogin"));
            } else {
                header("WWW-Authenticate: Basic realm=\"HotCRP\"");
            }
            exit;
        }

        // if user is still valid, OK
        if ($user->has_account_here()) {
            return;
        }

        // check HTTP auth
        if (!isset($_SERVER["REMOTE_USER"]) || !$_SERVER["REMOTE_USER"]) {
            header("HTTP/1.0 401 Unauthorized");
            $qreq->print_header("Error", "home");
            $conf->feedback_msg([
                MessageItem::error("<0>Authentication required"),
                MessageItem::inform("<0>This site is using HTTP authentication to manage its users, but you have not provided authentication data. This usually indicates a server configuration error.")
            ]);
            $qreq->print_footer();
            exit;
        }
        $qreq->email = $_SERVER["REMOTE_USER"];
        if (validate_email($qreq->email)) {
            $qreq->preferredEmail = $qreq->email;
        } else if (($x = $conf->opt("defaultEmailDomain"))
                   && validate_email($qreq->email . "@" . $x)) {
            $qreq->preferredEmail = $qreq->email . "@" . $x;
        }

        $info = self::login_info($conf, $qreq); // XXX
        if ($info["ok"]) {
            $conf->redirect($info["redirect"] ?? "");
        } else {
            header("HTTP/1.0 401 Unauthorized");
            $qreq->print_header("Error", "home");
            $conf->feedback_msg([
                MessageItem::error("<0>Authentication error"),
                MessageItem::inform("<0>This site is using HTTP authentication to manage its users. You have provided incorrect authentication data.")
            ]);
            $qreq->print_footer();
            exit;
        }
    }

    /** @return array{ok:true,user:Contact}|array{ok:false} */
    static private function user_lookup(Conf $conf, Qrequest $qreq) {
        // Look up the account information
        // to determine if the user is registered
        if (isset($qreq->email)) {
            $qreq->email = simplify_whitespace($qreq->email);
        }
        if (!isset($qreq->email) || $qreq->email === "") {
            return ["ok" => false, "email" => true, "noemail" => true];
        }
        if (strpos($qreq->email, "@") === false
            && strpos($qreq->email, "%40") !== false) {
            foreach ($qreq->keys() as $k) {
                $qreq[$k] = rawurldecode($qreq[$k]);
            }
        }
        return ["ok" => true, "user" => $conf->user_by_email($qreq->email)
            ?? Contact::make_keyed($conf, $qreq->subset_as_array(
                "firstName", "first", "lastName", "last", "name", "email", "affiliation"
            ))];
    }

    /** @return array{ok:true,user:Contact}|array{ok:false} */
    static function login_info(Conf $conf, Qrequest $qreq) {
        assert(!$conf->external_login());
        assert($qreq->valid_post());

        $info = self::user_lookup($conf, $qreq);
        if ($info["ok"]) {
            $info = $info["user"]->check_password_info(trim((string) $qreq->password));
        }
        return $info;
    }

    /** @return array{ok:true,user:Contact}|array{ok:false,disabled?:true,email?:true} */
    static function external_login_info(Conf $conf, Qrequest $qreq) {
        assert($conf->external_login());

        $info = self::user_lookup($conf, $qreq);
        if (!$info["ok"]) {
            return $info;
        }
        $user = $info["user"];

        // do LDAP login before validation, since we might create an account
        if ($conf->opt("ldapLogin")) {
            $info = LdapLogin::ldap_login_info($conf, $qreq);
            if (!$info["ok"]) {
                return $info;
            }
        }

        // auto-create account if external login
        if (!$user->contactId
            && !$user->store(Contact::SAVE_ANY_EMAIL)) {
            return ["ok" => false, "internal" => true, "email" => true];
        }

        // if user disabled, then fail
        if ($user->is_disabled()
            || (($cdbuser = $user->cdb_user()) && $cdbuser->is_disabled())) {
            return ["ok" => false, "disabled" => true, "email" => true];
        } else {
            return ["ok" => true, "user" => $user];
        }
    }

    /** @param array{ok:true,user:Contact} $info
     * @return array{ok:true,user:Contact,redirect:string,firstuser?:true} */
    static function login_complete($info, Qrequest $qreq) {
        assert($info["ok"] && $info["user"]);
        $luser = $info["user"];

        // mark activity
        $xuser = $luser->contactId ? $luser : $luser->cdb_user();
        $xuser->mark_login();

        // store authentication
        $qreq->qsession()->open_new_sid();
        self::change_session_users($qreq, [$xuser->email => 1]);

        // activate
        $user = $xuser->activate($qreq, false);
        $qreq->unset_csession("password_reset");

        $nav = $qreq->navigation();
        $url = $nav->server . $nav->base_path;
        if ($qreq->has_gsession("us")) {
            $url .= "u/" . Contact::session_index_by_email($qreq, $user->email) . "/";
        }
        $url .= "?postlogin=1";
        if ($qreq->redirect !== null && $qreq->redirect !== "1") {
            $url .= "&redirect=" . urlencode($qreq->redirect);
        }

        $info["user"] = $user;
        $info["redirect"] = $url;
        if (self::check_setup_phase($user)) {
            $info["firstuser"] = true;
        }
        return $info;
    }

    /** @param Qrequest $qreq
     * @param array<string,1|-1> $uinstr */
    static function change_session_users($qreq, $uinstr) {
        $us = Contact::session_users($qreq);
        $any_deleted = false;
        foreach ($uinstr as $e => $delta) {
            for ($i = 0; $i !== count($us); ++$i) {
                if (strcasecmp($us[$i], $e) === 0)
                    break;
            }
            if ($delta < 0 && $i !== count($us)) {
                array_splice($us, $i, 1);
                $any_deleted = true;
            } else if ($delta > 0 && $i === count($us)) {
                $us[] = $e;
            }
        }
        if (count($us) > 1) {
            $qreq->set_gsession("us", $us);
        } else {
            $qreq->unset_gsession("us");
        }
        if (empty($us)) {
            $qreq->unset_gsession("u");
        } else if ($qreq->gsession("u") !== $us[0]) {
            $qreq->set_gsession("u", $us[0]);
        }
        if ($any_deleted) {
            $qreq->unset_gsession("uchoice");
        }
    }

    /** @return bool */
    static private function check_setup_phase(Contact $user) {
        if ($user->conf->setting("setupPhase")) {
            $user->ensure_account_here()->save_roles(Contact::ROLE_ADMIN, null);
            $user->conf->save_setting("setupPhase", null);
            return true;
        } else {
            return false;
        }
    }

    static function check_postlogin(Contact $user, Qrequest $qreq) {
        // Check for the cookie
        if (!$qreq->has_gsession("v")) {
            $user->conf->feedback_msg([
                MessageItem::error($user->conf->_i("session_failed_error"))
            ]);
            return;
        }
        $qreq->unset_gsession("testsession");

        // Go places
        if (isset($qreq->redirect)) {
            $where = $qreq->redirect;
        } else if (($login_bounce = $qreq->gsession("login_bounce"))
                   && $login_bounce[0] === $user->conf->session_key) {
            $where = $login_bounce[1];
        } else {
            $qreq->set_csession("freshlogin", true);
            $where = $user->conf->hoturl_raw("index");
        }
        $user->conf->redirect($where);
        exit;
    }


    /** @return array{ok:true,user:Contact}|array{ok:false,email?:true} */
    static function new_account_info(Conf $conf, Qrequest $qreq) {
        assert($conf->allow_user_self_register());
        assert($qreq->valid_post());

        $info = self::user_lookup($conf, $qreq);
        if (!$info["ok"]) {
            return $info;
        }
        $user = $info["user"];

        $cdbu = $user->cdb_user();
        if ($cdbu && !$cdbu->password_unset()) {
            return [
                "ok" => false, "email" => true, "userexists" => true,
                "can_reset" => $cdbu->can_reset_password(),
                "contactdb" => true
            ];
        } else if (!$user->password_unset()) {
            return [
                "ok" => false, "email" => true, "userexists" => true,
                "can_reset" => $user->can_reset_password()
            ];
        } else if (!validate_email($qreq->email)) {
            return ["ok" => false, "email" => true, "invalidemail" => true];
        } else if (!$user->has_account_here() && !$user->store()) {
            return ["ok" => false, "email" => true, "internal" => true];
        } else {
            $conf->invalidate_user($user);
            $info = self::forgot_password_info($conf, $qreq, true);
            if ($info["ok"] && $info["mailtemplate"] === "@resetpassword") {
                $info["mailtemplate"] = "@newaccount.selfregister";
                if (self::check_setup_phase($info["user"])) {
                    $info["firstuser"] = true;
                }
            }
            return $info;
        }
    }


    /** @return array{ok:true,user:Contact,mailtemplate:string}|array{ok:false} */
    static function forgot_password_info(Conf $conf, Qrequest $qreq, $create) {
        if ($conf->external_login()) {
            return ["ok" => false, "email" => true, "noreset" => true];
        }

        $info = self::user_lookup($conf, $qreq);
        if (!$info["ok"]) {
            return $info;
        }
        $user = $info["user"];
        $cdbu = $user->cdb_user();

        // check for nonexistent users (placeholders count as nonexistent)
        if ((!$user->has_account_here() || $user->is_placeholder())
            && (!$cdbu || $cdbu->is_placeholder())
            && !$create) {
            return ["ok" => false, "email" => true, "unset" => true];
        }

        // check for users that cannot reset their password
        if (!$user->can_reset_password()) {
            return ["ok" => false, "email" => true, "nologin" => true];
        }

        // disabled users get mail saying they're disabled
        if ($user->is_disabled()
            || (!$user->contactId && !$conf->allow_user_self_register())
            || ($cdbu && $cdbu->is_disabled())) {
            $template = "@resetdisabled";
        } else {
            $template = "@resetpassword";
        }
        return ["ok" => true, "user" => $user, "mailtemplate" => $template];
    }


    /** @param bool $explicit
     * @return Contact */
    static function logout(Contact $user, Qrequest $qreq, $explicit) {
        $qsess = $qreq->qsession();
        if ($qsess->maybe_open()) {
            $qsess->clear();
            $qsess->commit();
        }
        if ($explicit) {
            if ($user->conf->opt("httpAuthLogin")) {
                $qsess->open_new_sid();
                $qsess->set("reauth", true);
            } else {
                unlink_session();
            }
        }
        $user = Contact::make($user->conf);
        unset($qreq->actas, $qreq->cap, $qreq->forceShow, $qreq->override);
        return $user->activate($qreq, false);
    }


    /** @param ?string $email
     * @param array $info
     * @param MessageSet $ms
     * @return void */
    static function login_error(Conf $conf, $email, $info, $ms) {
        $email = trim($email ?? "");
        $args = [];
        $e = "";
        if (isset($info["ldap"]) && isset($info["ldap_detail"])) {
            $e = $info["ldap_detail"];
        } else if (isset($info["noemail"])) {
            $e = $conf->opt("ldapLogin") ? "<0>Enter your username" : "<0>Enter your email address";
        } else if (isset($info["invalidemail"])) {
            $e = "<0>Enter a valid email address";
        } else if (isset($info["nocreate"])) {
            $e = "<0>Users can’t self-register for this site";
        } else if (isset($info["noreset"])) {
            $e = "<0>Password reset links aren’t used for this site. Contact your system administrator if you’ve forgotten your password.";
        } else if (isset($info["nologin"])) {
            $e = "<0>User {$email} is not allowed to sign in to this site";
        } else if (isset($info["userexists"])) {
            $e = "<0>User {$email} already has an account on this site";
            $args[] = new FmtArg("context", "account_exists");
        } else if (isset($info["unset"])) {
            $e = "<0>User {$email} does not have an account";
            $args[] = new FmtArg("context", "no_account");
            if ($conf->allow_user_self_register() && $email !== "") {
                $args[] = new FmtArg("newaccount", $conf->hoturl_raw("newaccount", ["email" => $email]));
            }
        } else if (isset($info["disabled"])) {
            $e = "<0>Your account on this site is disabled. Contact the site administrator for more information.";
        } else if (isset($info["reset"])) {
            $e = "<0>Your password has expired";
            $args[] = new FmtArg("context", "password_expired");
        } else if (isset($info["nopw"])) {
            $e = "<0>Enter your password";
        } else if (isset($info["nopost"])) {
            $e = "<0>Automatic login links have been disabled for security. Use this form to sign in.";
        } else if (isset($info["internal"])) {
            $e = "<0>Internal error";
        } else {
            $e = "<0>Incorrect password";
        }
        if ($email !== "") {
            $args[] = new FmtArg("email", $email, 0);
            $args[] = new FmtArg("signin", $conf->hoturl_raw("signin", ["email" => $email]), 0);
            if (isset($info["can_reset"])) {
                $args[] = new FmtArg("forgotpassword", $conf->hoturl_raw("forgotpassword", ["email" => $email]), 0);
            }
        }
        if (isset($info["invalid"]) && !Fmt::find_arg($args, "context")) {
            $args[] = new FmtArg("context", "bad_password");
        }
        $e = $conf->_i("signin_error", new FmtArg("message", $e), ...$args);
        $ms->error_at(isset($info["email"]) ? "email" : "password", $e);
        if (isset($info["password"])) {
            $ms->error_at("password");
        }
    }
}
