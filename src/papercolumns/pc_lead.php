<?php
// pc_lead.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Lead_PaperColumn extends PaperColumn {
    /** @var int */
    private $ianno;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    function add_decoration($decor) {
        return parent::add_user_sort_decoration($decor) || parent::add_decoration($decor);
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_lead(null)
            || (!$pl->conf->has_any_lead_or_shepherd() && !$visible)) {
            return false;
        }
        $pl->conf->pc_set(); // prepare cache
        return true;
    }
    static private function cid(PaperList $pl, PaperInfo $row) {
        if ($row->leadContactId > 0 && $pl->user->can_view_lead($row)) {
            return $row->leadContactId;
        } else {
            return 0;
        }
    }
    function prepare_sort(PaperList $pl, $sortindex) {
        $this->ianno = Contact::parse_sortspec($pl->conf, $this->decorations);
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        return $pl->user_compare(self::cid($pl, $a), self::cid($pl, $b), $this->ianno);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        return $pl->user_content($row->leadContactId, $row);
    }
    function text(PaperList $pl, PaperInfo $row) {
        return $pl->user_text($row->leadContactId);
    }
}
