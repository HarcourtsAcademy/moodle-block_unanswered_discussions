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

require_once($CFG->dirroot.'/blocks/unanswered_discussions/locallib.php');

class block_unanswered_discussions extends block_base {

    // Default Configuration
    public $defaultlimits = array(
        'randomposts' => 0, // Random Unanswered Posts
        'oldestposts' => 2, // Oldest Unanswered Posts
        'yourposts'   => 2  // Your Unanswered Posts
    );
    public $maxsubjectlength = 60; // characters
    public $maxshowoption = 10; // messages
    public $querylimit = 50; // messages

    //--------------------------------------------------------------------------
    public function init() {
        $this->title = get_string('unanswereddiscussions', 'block_unanswered_discussions');
    }

    //--------------------------------------------------------------------------
    public function applicable_formats() {
        return array('course-view' => true);
    }

    //--------------------------------------------------------------------------
    public function instance_allow_multiple() {
        return true;
    }

    //--------------------------------------------------------------------------
    public function has_config() {
        return false;
    }

    //--------------------------------------------------------------------------
    public function specialization() {
        global $COURSE, $DB;

        // Create the config object
        if (!isset($this->config)) {
            $this->config = new stdClass;
        }

        // Set up the default config values
        foreach ($this->defaultlimits as $name => $value) {
            if (!isset($this->config->{$name})) {
                $this->config->{$name} = $value;
            }
        }

        // Excluded News forums by default
        if (!isset($this->config->exclude)) {
            $this->config->exclude = array();
            $params = array('course'=>$COURSE->id, 'type'=>'news');
            if ($newsforums = $DB->get_records('forum', $params)) {
                foreach ($newsforums as $key => $forum) {
                    $this->config->exclude[] = $forum->id;
                }
            }
        }
    }

    //--------------------------------------------------------------------------
    private function get_data($course = 0) {
        global $CFG, $USER, $DB;

        // If we've already done it, return the results
        if (!empty($this->discussions)) {
            return $this->discussions;
        }
        $this->discussions = array();

        // Which course are we grabbing data for? Make sure it's an integer.
        $course = intval($course);

        // Exclude specified forums
        $where_fora_exclude_sql = '';
        if (!empty($this->config->exclude)) {
            $where_fora_exclude_sql = ' AND d.forum NOT IN(' . join($this->config->exclude, ',') . ') ';
        }
        $this->config->limits = array (
            $this->config->randomposts,
            $this->config->oldestposts,
            $this->config->yourposts
        );

        // These are the different bits in the three queries
        $queries = array(
            'where'  => array("AND d.userid <> {$USER->id} ", "AND d.userid <> {$USER->id} ", "AND d.userid = {$USER->id} "),
            'order'  => array('', 'd.timemodified ASC,', 'd.timemodified ASC,'),
        );

        /// Do it backwards and exclude previous results

        // This array holds already presented discussion ids to exclude for the next query (stops duplication)
        $discussion_exclude = array();

        // Run the three queries
        for ($i = 2; $i >= 0; $i--) {

            // No point doing the query if it's not enabled
            if (!$this->config->limits[$i]>0) {
                continue;
            }

            // If we've got excluded discussions build up the sql to exclude them
            $where_post_exclude_sql = (!empty($discussion_exclude) ? 'AND d.id NOT IN(' . join($discussion_exclude, ',') . ')' : '');

            // Building up the SQL statement from the bits and pieces above
            $sql = "SELECT d.id, d.forum, d.name, d.timemodified, d.groupid, (COUNT(p.id) - 1) AS replies, u.firstname, u.lastname
                    FROM {forum_posts} p, {forum_discussions} d, {user} u
                    WHERE d.course = $course
                          $where_fora_exclude_sql
                          $where_post_exclude_sql
                          AND d.id = p.discussion
                          AND u.id = d.userid
                          {$queries['where'][$i]}
                    GROUP BY d.id, d.forum, d.name, d.timemodified, d.groupid
                    HAVING COUNT(p.id) = 1
                    ORDER BY {$queries['order'][$i]}replies ASC";

            // Need to limit after query to achieve random shuffle
            $this->discussions[$i] = $DB->get_records_sql($sql, null, 0, $this->querylimit);

            // If it didn't get any results it doesn't need any processing
            if (empty($this->discussions[$i])) {
                unset($this->discussions[$i]);
                continue;
            }

            // Filter forums that are not visible or should appear to users in groupings
            foreach ($this->discussions[$i] as $key => $discussion) {
                $cm_info = get_fast_modinfo($course, $USER->id)->instances['forum'][$discussion->forum];
                if (!$cm_info->visible) {
                    unset($this->discussions[$i][$key]);
                }
            }
            $this->discussions[$i] = array_values($this->discussions[$i]);

            // For random posts, shuffle
            if ($i == 0) {
                shuffle($this->discussions[$i]);
            }

            // Reduce the number of posts down to the required level
            $this->discussions[$i] = array_slice($this->discussions[$i], 0, $this->config->limits[$i], true);

            // Add each discussion to the exclusion list
            reset($this->discussions[$i]);
            foreach ($this->discussions[$i] as $discussion) {
                $discussion_exclude[] = $discussion->id;
            }
        }

        return $this->discussions;
    }

    //--------------------------------------------------------------------------
    public function get_content() {
        global $COURSE, $CFG, $USER, $DB, $OUTPUT;

        // Don't do it more than once
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';

        if (empty($this->instance)) {
            return $this->content;
        }

        require_once($CFG->dirroot.'/mod/forum/lib.php');   // We'll need this

        // Do the data retreival. If we don't get anything, show a pretty message instead and return.
        $discussions = $this->get_data($COURSE->id);
        if (empty($discussions)) {
            $content = get_string('nounanswereddiscussions', 'block_unanswered_discussions');
            $this->content->text .= $OUTPUT->container($content, 'block_unanswered_discussions_message');
            return $this->content;
        }

        // Actually create the listing now
        $strftimedatetime = get_string('strftimedatetime');
        $strtitle = array(
            get_string('randomposts', 'block_unanswered_discussions'),
            get_string('oldestposts', 'block_unanswered_discussions'),
            get_string('yourposts', 'block_unanswered_discussions')
        );

        // Make sure our sections are in order
        ksort($this->discussions);
        reset($this->discussions);

        // Output each section
        foreach ($this->discussions as $key => $set) {

            // If this section's not enabled, or empty, skip it
            if (!$this->config->limits[$key] || empty($set)) {
                continue;
            }

            // Add the title for this section
            $this->content->text .= $OUTPUT->heading ($strtitle[$key], '4', 'block_unanswered_discussions_heading');

            // Make sure we get them all by resetting the array pointer
            reset($set);

            // Print each discussion
            foreach ($set as $discussion) {
                $discussion->subject = $discussion->name;
                $discussion->subject = format_string($discussion->subject, true, $COURSE->id);
                if (strlen($discussion->subject) > $this->maxsubjectlength) {
                    $discussion->subject = substr($discussion->subject, 0, $this->maxsubjectlength).'...';
                }

                $daysdiff = daysdiff(usertime(time()), usertime($discussion->timemodified));
                $dateclass = '';
                if ( 3 < $daysdiff and $daysdiff <= 5 ) {
                    $dateclass = ' alert alert-warning';
                } else if ($daysdiff > 5) {
                    $dateclass = ' alert alert-error';
                }

                $this->content->text
                    .= $OUTPUT->container_start('block_unanswered_discussions_item')
                    .  $OUTPUT->container_start('block_unanswered_discussions_message')
                    .  $OUTPUT->action_link('/mod/forum/discuss.php?d='.$discussion->id, $discussion->subject)
                    .  $OUTPUT->container_end()
                    .  $OUTPUT->container(timeAgo((int)usertime(time()), (int)usertime($discussion->timemodified)), 'block_unanswered_discussions_date' . $dateclass)
                    .  $OUTPUT->container('by ' . $discussion->firstname . ' ' . $discussion->lastname, 'block_unanswered_discussions_author')
                    .  $OUTPUT->container_end();
            }

        }

        return $this->content;
    }
}