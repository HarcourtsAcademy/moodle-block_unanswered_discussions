<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Unanswered Discussions block definition file
 *
 * @package    contrib
 * @subpackage block_unanswered_discussions
 * @copyright  2012 Michael de Raadt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/*
 * This function prints the difference between two php datetime objects
 * in a more human readable form
 * inputs should be like strtotime($date)
 * Adapted from https://gist.github.com/krishnakummar/1053741
 */
function timeAgo($now,$otherDate=null,$offset=null){
	if ($otherDate != null) {
		$offset = $now - $otherDate;
	}
    if ($offset == 0) {
        return get_string('past_now', 'block_unanswered_discussions');
    }
	if ($offset != null) {
		$deltaS = $offset%60;
		$offset /= 60;
		$deltaM = $offset%60;
		$offset /= 60;
		$deltaH = $offset%24;
		$offset /= 24;
		$deltaD = ($offset > 1)?ceil($offset):$offset;
	} else {
		throw new Exception("Must supply otherdate or offset (from now)");
	}
	if ($deltaD > 1) {
		return get_string('past_days', 'block_unanswered_discussions', $deltaD);
	}
	if (date('z', $otherDate) < date('z', $now) || $deltaD == 1) {
		return get_string('past_day', 'block_unanswered_discussions');
	}
	if ($deltaH == 1) {
		return get_string('past_hour', 'block_unanswered_discussions');
	}
	if ($deltaM == 1) {
		return get_string('past_minute', 'block_unanswered_discussions');;
	}
	if ($deltaH > 0) {
		return get_string('past_hours', 'block_unanswered_discussions', $deltaH);
	}
	if ($deltaM > 0) {
		return get_string('past_minutes', 'block_unanswered_discussions', $deltaM);
	}
	else {
		return get_string('past_now', 'block_unanswered_discussions');
	}
}

