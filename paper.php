<?php 
require_once('Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function actionTab($text, $url, $default, $disabled) {
    $sep = "<td class='sep'></td>";
    if ($disabled)
	return "$sep<td class='tab_disabled' nowrap='nowrap'>$text</td>";
    else if ($default)
	return "$sep<td class='tab_default' nowrap='nowrap'><a href='$url'>$text</a></td>";
    else
	return "$sep<td class='tab' nowrap='nowrap'><a href='$url'>$text</a></td>";
}

function actionBar($prow) {
    global $newPaper, $Me, $Conf, $editMode, $viewMode, $reviewsMode;
    if ($newPaper)
	$paperId = "new";
    else
	$paperId = ($prow == null ? -1 : $prow->paperId);
    $disableView = (!$newPaper && $paperId < 0);

    $x = "<table class='vubar'><tr><td><table><tr>";
    $x .= actionTab("View", "paper.php?paperId=$paperId&amp;mode=view", $viewMode, ($newPaper || $disableView));
    $x .= actionTab("Edit", "paper.php?paperId=$paperId&amp;mode=edit", $editMode, ($disableView || ($prow && $prow->author <= 0 && !$Me->amAssistant())));
    if (!$newPaper && $prow && ($Me->isPC || $Me->canViewReviews($prow, $Conf)))
	$x .= actionTab("Reviews" . ($prow ? " ($prow->reviewCount)" : ""), "paper.php?paperId=$paperId&amp;mode=reviews", $reviewsMode, false);
    $x .= "</tr></table></td><td class='spanner'></td><td class='gopaper' nowrap='nowrap'>" . goPaperForm() . "</td></tr></table>\n";
    return $x;
}

function confHeader() {
    global $paperId, $newPaper, $prow, $Conf;
    if ($paperId > 0)
	$title = "Paper #$paperId";
    else
	$title = ($newPaper ? "New Paper" : "Paper View");
    $Conf->header($title, "paper", actionBar($prow));
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID: either a number or "new"
$newPaper = false;
$paperId = -1;
if (!isset($_REQUEST["paperId"]))
    /* nada */;
else if (trim($_REQUEST["paperId"]) == "new")
    $newPaper = true;
else
    $paperId = cvtint($_REQUEST["paperId"]);


// mode
$editMode = $viewMode = $reviewsMode = false;
if ($newPaper || (isset($_REQUEST["mode"]) && $_REQUEST["mode"] == "edit"))
    $editMode = true;
else if (isset($_REQUEST["mode"]) && $_REQUEST["mode"] == "reviews")
    $reviewsMode = true;
else
    $viewMode = true;


// general error messages
if (isset($_REQUEST["post"]) && $_REQUEST["post"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");


// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me;
    $prow = $Conf->paperRow($paperId, $contactId, "while fetching paper");
    if ($prow === null)
	errorMsgExit("");
    else if (!$Me->canViewPaper($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "view", $paperId));
}
if (!$newPaper) {
    getProw($Me->contactId);
    // perfect mode: default to edit for non-submitted papers
    if ($viewMode && (!isset($_REQUEST["mode"]) || $_REQUEST["mode"] != "view")
	&& $prow->acknowledged <= 0) {
	$editMode = true;
	$viewMode = false;
    }
}


// update paper action
$PaperError = array();

function setRequestFromPaper($prow) {
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if (!isset($_REQUEST[$x]))
	    $_REQUEST[$x] = $prow->$x;
}

function requestSameAsPaper($prow) {
    global $Conf;
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if ($_REQUEST[$x] != $prow->$x)
	    return false;
    if (fileUploaded($_FILES['paperUpload'], $Conf))
	return false;
    $result = $Conf->q("select TopicArea.topicId, PaperTopic.paperId from TopicArea left join PaperTopic on PaperTopic.paperId=$prow->paperId and PaperTopic.topicId=TopicArea.topicId");
    if (!DB::isError($result))
	while (($row = $result->fetchRow())) {
	    $got = isset($_REQUEST["top$row[0]"]) && cvtint($_REQUEST["top$row[0]"]) > 0;
	    if (($row[1] > 0) != $got)
		return false;
	}
    return true;
}

function uploadPaper() {
    global $prow, $Conf;
    $result = $Conf->storePaper('paperUpload', $prow);
    if ($result == 0 || PEAR::isError($result)) {
	$Conf->errorMsg("There was an error while trying to update your paper.  Please try again.");
	return false;
    }
    return true;
}

function updatePaper($Me, $isSubmit, $isUploadOnly) {
    global $paperId, $newPaper, $PaperError, $Conf, $prow;
    $contactId = $Me->contactId;

    // check that all required information has been entered
    array_ensure($_REQUEST, "", "title", "abstract", "authorInformation", "collaborators");
    $q = "";
    foreach (array("title", "abstract", "authorInformation", "collaborators") as $x)
	if (trim($_REQUEST[$x]) == "" && ($isSubmit || $x != "collaborators"))
	    $PaperError[$x] = 1;
	else
	    $q .= "$x='" . sqlqtrim($_REQUEST[$x]) . "', ";

    // any missing fields?
    if (count($PaperError) > 0) {
	$Conf->errorMsg("One or more required fields were left blank.  Fill in those fields and try again." . (isset($PaperError["collaborators"]) ? "  If none of the authors have recent collaborators, just enter \"None\" in the Collaborators field." : ""));
	return false;
    }

    // defined contact ID
    if ($newPaper && (isset($_REQUEST["contact_email"]) || isset($_REQUEST["contact_name"])) && $Me->amAssistant())
	if (!($contactId = $Conf->getContactId($_REQUEST["contact_email"], "contact_"))) {
	    $Conf->errorMsg("You must supply a valid email address for the contact author.");
	    $PaperError["contactAuthor"] = 1;
	    return false;
	}

    // update Paper table
    $q = substr($q, 0, -2);
    if (!$newPaper)
	$q .= " where paperId=$paperId and withdrawn<=0 and acknowledged<=0";
    else
	$q .= ", contactId=$contactId, paperStorageId=1";
    $result = $Conf->qe(($newPaper ? "insert into" : "update") . " Paper set $q", "while updating paper information");
    if (DB::isError($result))
	return false;

    // fetch paper ID
    if ($newPaper) {
	$result = $Conf->qe("select last_insert_id()", "while updating paper information");
	if (DB::isError($result) || $result->numRows() == 0)
	    return false;
	$row = $result->fetchRow();
	$paperId = $row[0];

	$result = $Conf->qe("insert into PaperConflict set paperId=$paperId, contactId=$contactId, author=1", "while updating paper information");
	if (DB::isError($result))
	    return false;
    }

    // update topics table
    $result = $Conf->qe("delete from PaperTopic where paperId=$paperId", "while updating paper topics");
    if (DB::isError($result))
	return false;
    foreach ($_REQUEST as $key => $value)
	if ($key[0] == 't' && $key[1] == 'o' && $key[2] == 'p'
	    && ($id = cvtint(substr($key, 3))) > 0 && $value > 0) {
	    $result = $Conf->qe("insert into PaperTopic set paperId=$paperId, topicId=$id", "while updating paper topics");
	    if (DB::isError($result))
		return false;
	}

    // upload paper if appropriate
    if (fileUploaded($_FILES['paperUpload'], $Conf)) {
	if ($newPaper)
	    getProw($contactId);
	if (!uploadPaper())
	    return false;
    }

    // submit paper if appropriate
    if ($isSubmit) {
	getProw($contactId);
	if ($prow->paperStorageId == 1) {
	    $PaperError["paper"] = 1;
	    return $Conf->errorMsg(whyNotText("notUploaded", "submit", $paperId));
	}
	$result = $Conf->qe("update Paper set acknowledged=" . time() . " where paperId=$paperId", "while submitting paper");
	if (DB::isError($result))
	    return false;
    }
    
    // confirmation message
    getProw($contactId);
    $what = ($isSubmit ? "Submitted" : ($newPaper ? "Created" : "Updated"));
    $Conf->confirmMsg("$what paper #$paperId.");

    // send paper email
    $subject = "[$Conf->shortName] Paper #$paperId " . strtolower($what);
    $message = ",\n\n"
	. wordwrap("This mail confirms the " . ($isSubmit ? "submission" : ($newPaper ? "creation" : "update")) . " of paper #$paperId at the $Conf->shortName conference submission site.") . "\n\n"
	. wordWrapIndent(trim($prow->title), "Title: ") . "\n"
	. wordWrapIndent(trim($prow->authorInformation), "Authors: ") . "\n"
	. "      Paper site: $Conf->paperSite/paper.php?paperId=$paperId\n\n";
    if ($isSubmit)
	$m = "The paper will be considered for inclusion in the conference.  You will receive email when reviews are available for you to view.";
    else {
	$m = "The paper has not been submitted yet.";
	$deadline = $Conf->printableEndTime("updatePaperSubmission");
	if ($deadline != "N/A")
	    $m .= "  You have until $deadline to update the paper further.";
	$deadline = $Conf->printableEndTime("finalizePaperSubmission");
	if ($deadline != "N/A")
	    $m .= "  If you do not officially submit the paper by $deadline, it will not be considered for the conference.";
    }
    $message .= wordwrap("$m\n\nContact the site administrator, $Conf->contactName ($Conf->contactEmail), with any questions or concerns.

- $Conf->shortName Conference Submissions\n");

    // send email to all contact authors
    $result = $Conf->qe("select firstName, lastName, email from ContactInfo join PaperConflict using (contactId) where paperId=$paperId and author>0", "while looking up contact authors to send email");
    if (!DB::isError($result)) {
	while (($row = $result->fetchRow())) {
	    $m = "Dear " . contactText($row[0], $row[1], $row[2]) . $message;
	    if ($Conf->allowEmailTo($row[2]))
		mail($row[2], $subject, $m, "From: $Conf->emailFrom");
	    else
		$Conf->infoMsg("<pre>$subject\n\n" . htmlspecialchars($m) . "</pre>");
	}
    }
    
    return true;
}

if (isset($_REQUEST["update"]) || isset($_REQUEST["submit"])) {
    // get missing parts of request
    if (!$newPaper)
	setRequestFromPaper($prow);

    // check deadlines
    if ($newPaper)
	$ok = $Me->canStartPaper($Conf, $whyNot);
    else {
	if (isset($_REQUEST["submit"]) && requestSameAsPaper($prow))
	    $ok = $Me->canFinalizePaper($prow, $Conf, $whyNot);
	else
	    $ok = $Me->canUpdatePaper($prow, $Conf, $whyNot);
    }

    // actually update
    if (!$ok)
	$Conf->errorMsg(whyNotText($whyNot, "update", $paperId));
    else if (updatePaper($Me, isset($_REQUEST["submit"]), false)) {
	if ($newPaper)
	    $Conf->go("paper.php?paperId=$paperId&mode=edit");
    }

    // use request?
    $useRequest = ($ok || $Me->amAssistant());
}


// unfinalize, withdraw, and revive actions
if (isset($_REQUEST["unsubmit"]) && !$newPaper) {
    if ($Me->amAssistant()) {
	$Conf->qe("update Paper set acknowledged=0 where paperId=$paperId", "while undoing paper submit");
	getProw($Me->contactId);
    } else
	$Conf->errorMsg("Only the program chairs can undo paper submission.");
}
if (isset($_REQUEST["withdraw"]) && !$newPaper) {
    if ($Me->canWithdrawPaper($prow, $Conf, $whyNot)) {
	$Conf->qe("update Paper set withdrawn=" . time() . " where paperId=$paperId", "while withdrawing paper");
	getProw($Me->contactId);
    } else
	$Conf->errorMsg(whyNotText($whyNot, "withdraw", $paperId));
}
if (isset($_REQUEST["revive"]) && !$newPaper) {
    if ($Me->canRevivePaper($prow, $Conf, $whyNot)) {
	$Conf->qe("update Paper set withdrawn=0 where paperId=$paperId", "while reviving paper");
	getProw($Me->contactId);
    } else
	$Conf->errorMsg(whyNotText($whyNot, "revive", $paperId));
}


// messages for the author
function deadlineIs($dname, $conf) {
    $deadline = $conf->printableEndTime($dname);
    if ($deadline == "N/A")
	return "";
    else if (time() < $conf->endTime[$dname])
	return "  The deadline is $deadline.";
    else
	return "  The deadline was $deadline.";
}

$override = ($Me->amAssistant() ? "  As PC Chair, you can override this deadline using the \"Override deadlines\" checkbox." : "");
if (!$editMode)
    /* do nothing */;
else if ($newPaper) {
    $timeStart = $Conf->timeStartPaper();
    $startDeadline = deadlineIs("startPaperSubmission", $Conf);
    if (!$timeStart) {
	$msg = "You cannot start new papers since the <a href='deadlines.php'>deadline</a> has passed.$startDeadline$override";
	if (!$Me->amAssistant())
	    errorMsgExit($msg);
	$Conf->infoMsg($msg);
    }
} else if ($prow->author > 0 && $prow->acknowledged <= 0) {
    $timeUpdate = $Conf->timeUpdatePaper();
    $updateDeadline = deadlineIs("updatePaperSubmission", $Conf);
    $timeSubmit = $Conf->timeFinalizePaper();
    $submitDeadline = deadlineIs("finalizePaperSubmission", $Conf); 
    if ($timeUpdate && $prow->withdrawn > 0)
	$Conf->infoMsg("Your paper has been withdrawn, but you can still revive it.$updateDeadline");
    else if ($timeUpdate)
	$Conf->infoMsg("You must officially submit your paper before it can be reviewed.  <strong>This step cannot be undone</strong> and you can't make changes after submitting, so make all necessary changes first.$updateDeadline");
    else if ($prow->withdrawn <= 0 && $timeSubmit)
	$Conf->infoMsg("You cannot update your paper since the <a href='deadlines.php'>deadline</a> has passed, but it still must be officially submitted before it can be considered for the conference.$submitDeadline$override");
    else if ($prow->withdrawn <= 0)
	$Conf->infoMsg("The <a href='deadlines.php'>deadline</a> for submitting this paper has passed.  The paper will not be considered.$submitDeadline$override");
} else if ($prow->author > 0) {
    $override2 = ($Me->amAssistant() ? "  As PC Chair, you can unsubmit the paper, which will allow further changes, using the \"Undo Submit\" button." : "");
    $Conf->infoMsg("This paper has been submitted and can no longer be changed.  You can still withdraw the paper or add contact authors, allowing others to view reviews as they become available.$override2");
}
if ($editMode && !$newPaper && !$prow->author)
    $Conf->infoMsg("You are not an author of this paper, but can still make changes as PC Chair.");


if (isset($_REQUEST['setoutcome'])) {
    if (!$Me->canSetOutcome($prow))
	$Conf->errorMsg("You cannot set the outcome for paper #$paperId" . ($Me->amAssistant() ? " (but you could if you entered chair mode)" : "") . ".");
    else {
	$o = cvtint(trim($_REQUEST['outcome']));
	$rf = reviewForm();
	if (isset($rf->options['outcome'][$o])) {
	    $result = $Conf->qe("update Paper set outcome=$o where paperId=$paperId", "while changing outcome");
	    if (!DB::isError($result))
		$Conf->confirmMsg("Outcome for paper #$paperId set to " . htmlspecialchars($rf->options['outcome'][$o]) . ".");
	} else
	    $Conf->errorMsg("Bad outcome value!");
	$prow = $Conf->paperRow($paperId, $Me->contactId);
    }
}


confHeader();


function caption_class($what) {
    global $PaperError;
    if (isset($PaperError[$what]))
	return "caption error";
    else
	return "caption";
}

function pt_data($what, $rows, $authorTable = false) {
    global $editMode, $editable, $newPaper, $prow, $useRequest;
    if ($editable)
	echo "<textarea class='textlite' name='$what' rows='$rows' cols='80' onchange='highlightUpdate()'>";
    if ($useRequest)
	$text = $_REQUEST[$what];
    else if (!$newPaper)
	$text = $prow->$what;
    else
	$text = "";
    if ($authorTable && !$editable)
	echo authorTable($text, true);
    else
	echo htmlspecialchars($text);
    if ($editable)
	echo "</textarea>";
}


// begin table
if ($editMode) {
    echo "<form method='post' action=\"paper.php?paperId=",
	($newPaper ? "new" : $paperId),
	"&amp;post=1\" enctype='multipart/form-data'>";
    $editable = $newPaper || ($prow->acknowledged <= 0 && $prow->withdrawn <= 0
			      && ($Conf->timeUpdatePaper() || $Me->amAssistant()));
} else
    $editable = false;
echo "<table class='paper'>\n\n";


// title
if (!$newPaper) {
    echo "<tr class='id'>\n  <td class='caption'><h2>#$paperId</h2></td>\n";
    echo "  <td class='entry' colspan='2'><h2>", htmlspecialchars($prow->title), "</h2></td>\n</tr>\n\n";
}


// paper status
if (!$newPaper) {
    echo "<tr id='foldst' class='fold1ed'>\n  <td class='caption'>Status</td>\n";
    echo "  <td class='entry'>", $Me->paperStatus($paperId, $prow, 1);
    if ($reviewsMode)
	echo "&nbsp;&nbsp;&nbsp; ",
	    "<a href=\"javascript:fold(['st','pa','ab','sa','ca','au','co','to'], 0, 1)\" class='button_small unfolder1'>Show&nbsp;paper&nbsp;information</a>",
	    "<a href=\"javascript:fold(['st','pa','ab','sa','ca','au','co','to'], 1, 1)\" class='button_small folder1'>Hide&nbsp;paper&nbsp;information</a>";
    if ($prow->author > 0)
	echo "<br/>\nYou are an <span class='author'>author</span> of this paper.";
    else if ($Me->isPC && $prow->conflict > 0)
	echo "<br/>\nYou have a <span class='conflict'>conflict</span> with this paper.";
    if ($prow->reviewType != null && $viewMode) {
	if ($prow->reviewType == REVIEW_PRIMARY)
	    echo "<br/>\nYou are a primary reviewer for this paper.";
	else if ($prow->reviewType == REVIEW_SECONDARY)
	    echo "<br/>\nYou are a secondary reviewer for this paper.";
	else if ($prow->reviewType == REVIEW_REQUESTED)
	    echo "<br/>\nYou were requested to review this paper.";
	else
	    echo "<br/>\nYou began a review for this paper.";
    }
    echo "</td>\n</tr>\n\n";
}


// Editable title
if ($editable) {
    echo "<tr class='pt_title'>\n  <td class='",
	caption_class("title"), "'>Title</td>\n";
    echo "  <td class='entry'>";
    pt_data("title", 1);
    echo "</td>\n</tr>\n\n";
}


// Outcome
if (!$editMode && $Me->canSetOutcome($prow)) {
    echo "<tr class='pt_outcome'>
  <td class='caption'>Outcome</td>
  <td class='entry'><form method='get' action='paper.php'><input type='hidden' name='paperId' value='$paperId' /><select class='outcome' name='outcome'>\n";
    $rf = reviewForm();
    $outcomeMap = $rf->options['outcome'];
    $outcomes = array_keys($outcomeMap);
    sort($outcomes);
    $outcomes = array_unique(array_merge(array(0), $outcomes));
    foreach ($outcomes as $key)
	echo "    <option value='", $key, "'", ($prow->outcome == $key ? " selected='selected'" : ""), ">", htmlspecialchars($outcomeMap[$key]), "</option>\n";
    echo "  </select>&nbsp;<input class='button_small' type='submit' name='setoutcome' value='Set outcome' /></form></td>\n</tr>\n";
}


// Collect reviews, review scores
if ($reviewsMode) {
    $rrows = array();
    $showReviews = $Me->canViewReviews($prow, $Conf, $whyNot);
    if (!$showReviews) {
	echo "<tr class='pt_reviews'>\n  <td class='caption'></td>\n  <td class='entry'>";
	$Conf->infoMsg(whyNotText($whyNot, "view reviews for", $paperId));
	echo "</td>\n</tr>\n\n";
    } else {
	$q = "select PaperReview.*,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from PaperReview
		join ContactInfo using (contactId)
		where paperId=$paperId
		order by reviewSubmitted";
	$result = $Conf->qe($q, "while retrieving reviews");
	if (!DB::isError($result))
	    while ($rrow = $result->fetchRow(DB_FETCHMODE_OBJECT))
		$rrows[] = $rrow;
    }
    if (count($rrows) > 0) {
	echo "<tr class='pt_reviews'>\n  <td class='caption'>Reviews</td>\n  <td class='entry'>";
	$rf = reviewForm();
	echo $rf->webNumericScoresTable($rrows, $prow, $Me, $Conf, true);
	echo "</td>\n</tr>\n\n";
    }
}


// Paper information folding
if ($reviewsMode) {
    $trClasses = " fold1ed";
    $tdClasses = " extension1";
} else
    $trClasses = $tdClasses = "";


// Paper
if ($newPaper || ($prow->withdrawn <= 0 && ($editable || $prow->size > 0))) {
    echo "<tr class='pt_paper$trClasses' id='foldpa'>\n  <td class='",
	caption_class("paper"), $tdClasses, "'>Paper",
	($newPaper ? " (optional)" : ""), "</td>\n";
    echo "  <td class='entry", $tdClasses, "'>";
    if (!$newPaper && $prow->size > 0)
	echo paperDownload($paperId, $prow, 1);
    if ($newPaper || ($editMode && $prow->acknowledged <= 0)) {
	if (!$newPaper && $prow->size > 0)
	    echo "<br/>\n    ";
	echo "<input class='textlite' type='file' name='paperUpload' accept='application/pdf application/postscript' size='", ($newPaper ? 30 : 30), "' />";
	if (!$newPaper && 0)
	    echo "&nbsp;<input class='button' type='submit' name='upload' value='Upload paper' />";
    }
    echo "</td>\n";
    if ($newPaper || ($editMode && $prow->acknowledged <= 0))
	echo "  <td class='hint$tdClasses'>Max size: ", ini_get("upload_max_filesize"), "B</td>\n";
    echo "</tr>\n\n";
}


// PC conflicts
if (!$editMode && $Me->amAssistant()) {
    $q = "select firstName, lastName
	from ContactInfo
	join PCMember using (contactId)
	join PaperConflict using (contactId)
	where paperId=$paperId group by ContactInfo.contactId";
    $result = $Conf->qe($q, "while finding conflicted PC members");
    if (!DB::isError($result)) {
	while ($row = $result->fetchRow())
	    $pcConflicts[] = "$row[0] $row[1]";
	if (!isset($pcConflicts))
	    $pcConflicts[] = "None";
	echo "<tr class='pt_conflict'>\n  <td class='caption'>PC conflicts</td>\n  <td class='entry'>", authorTable($pcConflicts), "</td>\n</tr>\n\n";
    }
}


// Abstract
echo "<tr class='pt_abstract", $trClasses, "' id='foldab'>\n  <td class='",
    caption_class("abstract"), $tdClasses,
    "'>Abstract</td>\n  <td class='entry", $tdClasses, "'>";
pt_data("abstract", 5);
echo "</td>\n</tr>\n\n";


// Author area
$canViewAuthors = $Me->canViewAuthors($prow, $Conf);
if ($editMode && $Me->amAssistant())
    $canViewAuthors = true;
if (!$canViewAuthors && $Me->amAssistant()) {
    $folders = "['sa','ca','au','co']";
    echo "<tr class='pt_authorTrigger folded$trClasses' id='foldsa'>\n",
	"  <td class='caption$tdClasses'></td>\n",
	"  <td class='entry$tdClasses'><a class='button unfolder' href=\"javascript:fold($folders, 0)\">Show&nbsp;authors</a><a class='button folder' href=\"javascript:fold($folders, 1)\">Hide&nbsp;authors</a></td>\n",
	"</tr>\n\n";
    $authorTRClasses = $trClasses . " folded";
    $authorTDClasses = $tdClasses . " extension";
} else {
    $authorTRClasses = $trClasses;
    $authorTDClasses = $tdClasses;
}


// Contact authors
if ($newPaper) {
    echo "<tr class='pt_contactAuthor$authorTRClasses' id='foldca'>\n  <td class='", caption_class('contactAuthor'), $authorTDClasses, "'>";
    echo "Contact author</td>\n  <td class='entry$authorTDClasses'>";
    if ($Me->amAssistant())
	contactPulldown("contact", "contact", $Conf, $Me);
    else
	echo contactText($Me->firstName, $Me->lastName, $Me->email);
    echo "</td>\n";
    echo "  <td class='hint$authorTDClasses'>You will be able to add more contact authors after you submit the paper.</td>\n";
} else if ($canViewAuthors || $Me->amAssistant()) {
    $result = $Conf->qe("select firstName, lastName, email, contactId
	from ContactInfo
	join PaperConflict using (contactId)
	where paperId=$paperId and author=1
	order by lastName, firstName", "while finding contact authors");
    echo "<tr class='pt_contactAuthor$authorTRClasses' id='foldca'>\n  <td class='caption$authorTDClasses'>";
    echo (!DB::isError($result) && $result->numRows() == 1 ? "Contact author" : "Contact authors");
    echo "</td>\n  <td class='entry$authorTDClasses'>";
    if (!DB::isError($result)) {
	while ($row = $result->fetchRow()) {
	    $au = contactText($row[0], $row[1], $row[2]);
	    $aus[] = $au;
	}
	echo authorTable($aus, false);
    }
    if ($editMode)
	echo "<a class='button_small' href='contactauthors.php?paperId=$paperId'>Edit&nbsp;contact&nbsp;authors</a>";
    echo "</td>\n</tr>\n\n";
}


// Authors
if ($newPaper || $canViewAuthors || $Me->amAssistant()) {
    echo "<tr class='pt_authors$authorTRClasses' id='foldau'>\n  <td class='",
	caption_class("authorInformation"), $authorTDClasses,
	"'>Authors</td>\n  <td class='entry$authorTDClasses'>";
    pt_data("authorInformation", 5, true);
    echo "</td>\n";
    if ($editable)
	echo "  <td class='hint$authorTDClasses'>List the paper's authors one per line, including any affiliations.  Example: <pre class='entryexample'>Bob Roberts (UCLA)
Ludwig van Beethoven (Colorado)
Zhang, Ping Yen (INRIA)</pre></td>\n";
    echo "</tr>\n\n";

    echo "<tr class='pt_collaborators$authorTRClasses' id='foldco'>\n  <td class='",
	caption_class("collaborators"), $authorTDClasses,
	"'>Collaborators</td>\n  <td class='entry$authorTDClasses'>";
    pt_data("collaborators", 5, true);
    echo "</td>\n";
    if ($editable)
	echo "  <td class='hint$authorTDClasses'>List the authors' recent (~2 years) coauthors and collaborators, and any advisor or student relationships.  Be sure to include PC members when appropriate.  We use this information to avoid conflicts of interest when reviewers are assigned.  Use the same format as for authors, above.</td>\n";
    echo "</tr>\n\n";
}


// Topics
$topicMode = (int) $useRequest;
if (!$editMode || (!$newPaper && ($prow->acknowledged > 0 || $prow->withdrawn > 0)))
    $topicMode = -1;
if ($topicTable = topicTable($paperId, $topicMode, $Conf)) { 
    echo "<tr class='pt_topics$trClasses' id='foldto'>
  <td class='caption$tdClasses'>Topics</td>
  <td class='entry$tdClasses' id='topictable'>", $topicTable, "</td>\n</tr>\n\n";
}


// Submit button
if ($editMode) {
    echo "<tr class='pt_edit'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>\n";
    $buttons = array();
    if ($newPaper)
	$buttons[] = "<input class='button_default' type='submit' name='update' value='Create paper' />";
    else if ($prow->withdrawn > 0 && ($Conf->timeFinalizePaper() || $Me->amAssistant()))
	$buttons[] = "<input class='button' type='submit' name='revive' value='Revive paper' />";
    else if ($prow->withdrawn > 0)
	$buttons[] = "The paper has been withdrawn, and the <a href='deadlines.php'>deadline</a> for reviving it has passed.";
    else {
	if ($prow->acknowledged <= 0) {
	    if ($Conf->timeUpdatePaper() || $Me->amAssistant())
		$buttons[] = array("<input class='button' type='submit' name='update' value='Save changes' />", "(does not submit paper)");
	    if ($Conf->timeFinalizePaper() || $Me->amAssistant())
		$buttons[] = array("<input class='button_default' type='submit' name='submit' value='Submit paper' />", "(cannot undo)");
	} else if ($Me->amAssistant())
	    $buttons[] = array("<input class='button' type='submit' name='unsubmit' value='Undo submit' />", "(PC chair only)"); 
	$buttons[] = "<input class='button' type='submit' name='withdraw' value='Withdraw paper' />";
    }
    echo "    <tr>";
    foreach ($buttons as $b) {
	$x = (is_array($b) ? $b[0] : $b);
	echo "<td class='ptb_button'>", $x, "</td>";
    }
    echo "</tr>\n    <tr>";
    foreach ($buttons as $b) {
	$x = (is_array($b) ? $b[1] : "");
	echo "<td class='ptb_explain'>", $x, "</td>";
    }
    echo "</tr>\n";
    if ($Me->amAssistant())
	echo "    <tr><td colspan='", count($buttons), "'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td></tr>\n";
    echo "  </table></td>\n</tr>\n\n";
}


// End paper view
echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='2'></td></tr>
</table>\n";
if ($editMode)
    echo "</form>\n";
echo "<div class='clear'></div>\n\n";


// Reviews
if (!$newPaper && $reviewsMode && $prow->reviewCount > 0) {
    if ($Me->canViewReviews($prow, $Conf, $whyNot)) {
	$rf = reviewForm();
	$q = "select PaperReview.*,
		ContactInfo.firstName, ContactInfo.lastName, ContactInfo.email
		from PaperReview
		join ContactInfo using (contactId)
		where paperId=$paperId
		order by reviewSubmitted";
	$result = $Conf->qe($q, "while retrieving reviews");
	$reviewnum = 65;
	if (!DB::isError($result) && $result->numRows() > 0)
	    while ($rrow = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
		echo "

<table class='rev'>
  <tr class='id'>
    <td class='caption'><h3 id='review", chr($reviewnum), "'>Review&nbsp;", chr($reviewnum), "</h3></td>
    <td class='entry' colspan='3'>";
		if ($Me->canViewReviewerIdentity($rrow, $prow, $Conf))
		    echo "by <span class='reviewer'>", trim(htmlspecialchars("$rrow->firstName $rrow->lastName")), "</span>";
		echo " <span class='reviewstatus'>", reviewStatus($rrow, 1), "</span>";
		if ($rrow->contactId == $Me->contactId || $Me->amAssistant())
		    echo " ", reviewButton($paperId, $rrow, 0, $Conf);
		echo "</td>
  </tr>\n";
		echo $rf->webDisplayRows($rrow, $Me->canViewAllReviewFields($prow, $Conf));
		 echo "<tr class='last'><td class='caption'></td><td class='entry' colspan='3'></td></tr>
</table>\n\n";
		 $reviewnum++;
	    }

    } else {
	echo "<hr/>\n<p>";
	if ($Me->isPC || $prow->reviewType > 0)
	    echo plural($nreviews, "review"), " available for paper #$paperId.  ";
	echo whyNotText($whyNot, "viewreview", $paperId);
    }
}


$Conf->footer(); ?>
