<?php
// pc_preferencelist.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PreferenceList_PaperColumn extends PaperColumn {
    /** @var bool */
    private $topics;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->topics = !!($cj->topics ?? false);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY_LINK;
    }
    function add_decoration($decor) {
        if ($decor === "topic" || $decor === "topics") {
            $this->topics = true;
            return $this->__add_decoration("topics");
        } else {
            return parent::add_decoration($decor);
        }
    }
    function prepare(PaperList $pl, $visible) {
        if ($this->topics && !$pl->conf->has_topics()) {
            $this->topics = false;
        }
        if (!$pl->user->is_manager()) {
            return false;
        }
        if ($visible) {
            $pl->qopts["allReviewerPreference"] = true;
            if ($this->topics) {
                $pl->qopts["topics"] = true;
            }
            $pl->conf->stash_hotcrp_pc($pl->user);
        }
        return true;
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_administer($row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $ts = [];
        if ($this->topics || $row->preferences()) {
            foreach ($row->conf->pc_members() as $pcid => $pc) {
                if (($pref = $row->preference($pcid, $this->topics))) {
                    if ($pref[0] !== 0 || $pref[1] !== null) {
                        $ts[] = $pcid . "P" . $pref[0] . ($pref[1] !== null ? unparse_expertise($pref[1]) : "");
                    } else if ($this->topics && $pref[2]) {
                        $ts[] = $pcid . "T" . $pref[2];
                    }
                }
            }
        }
        $pl->row_attr["data-allpref"] = join(" ", $ts);
        if (!empty($ts)) {
            $t = '<span class="need-allpref">Loading</span>';
            $pl->need_render = true;
            return $t;
        } else {
            return '';
        }
    }
}
