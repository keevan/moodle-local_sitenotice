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
 *
 * @package local_sitenotice
 * @author  Nathan Nguyen <nathannguyen@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_sitenotice;

use core_competency\url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/cohort/lib.php');

class helper {

    public static function create_new_notice($data) {
        global $DB, $USER;
        $data->timecreated = time();
        $data->timemodified = time();
        $transaction = $DB->start_delegated_transaction();
        $noticeid = $DB->insert_record('local_sitenotice', $data);
        if (!empty($noticeid)) {
            $dom = new \DOMDocument();
            $dom->loadHTML($data->content);
            foreach ($dom->getElementsByTagName('a') as $node) {
                $link = new \stdClass();
                $link->noticeid = $noticeid;
                $link->text = trim($node->nodeValue);
                $link->link = trim($node->getAttribute("href"));
                $linkid = $DB->insert_record('local_sitenotice_hlinks', $link);
                $node->setAttribute('data-linkid', $linkid);
            }
            $content = $dom->saveHTML();
            $result = $DB->set_field('local_sitenotice', 'content', $content, ['id' => $noticeid]);
            if ($result) {
                // Log created event.
                $params = array(
                    'context' => \context_system::instance(),
                    'objectid' => $noticeid,
                    'relateduserid' => $USER->id,
                );
                $event = \local_sitenotice\event\sitenotice_created::create($params);
                $event->trigger();
            }
        }
        $transaction->allow_commit();
    }

    public static function retrieve_notice($noticeid) {
        global $DB;
        return $DB->get_record('local_sitenotice', ['id' => $noticeid]);
    }

    public static function retrieve_all_notices($sort = '') {
        global $DB;
        return $DB->get_records('local_sitenotice', null, $sort);
    }

    public static function retrieve_enabled_notices($sort = '') {
        global $DB;
        return $DB->get_records('local_sitenotice', ['enabled' => 1], $sort);
    }

    public static function retrieve_user_notices($userid) {
        global $USER;
        if (!$userid) {
            return [];
        }
        $notices = self::retrieve_enabled_notices();
        if (isset($USER->viewednotices)) {
            $notices = array_diff_key($notices, $USER->viewednotices);
        }
        return $notices;
    }

    public static function reset_notice($noticeid, $enabled = null) {
        global $DB, $USER;
        $notice = self::retrieve_notice($noticeid);
        $action = 'reset';
        if (isset($enabled)) {
            $notice->enabled = $enabled;
            $action = $enabled ? 'enabled' : 'disabled';
        }
        $notice->timemodified = time();
        $result = $DB->update_record('local_sitenotice', $notice);
        if ($result) {
            // Log event.
            $params = array(
                'context' => \context_system::instance(),
                'objectid' => $noticeid,
                'relateduserid' => $USER->id,
                'other' => array('action' => $action),
            );
            $event = \local_sitenotice\event\sitenotice_updated::create($params);
            $event->trigger();
        }
        return $result;
    }

    public static function built_audience_option() {
        $option = ['0' => 'All Users'];
        $cohorts = cohort_get_all_cohorts();
        foreach ($cohorts['cohorts'] as $cohort) {
            $option[$cohort->id] = $cohort->name;
        }
        return $option;
    }

    public static function dismiss_notice($noticeid) {
        global $USER;
        $USER->viewednotices[$noticeid] = time();
        $notice = self::retrieve_notice($noticeid);
        if ($notice && $notice->reqack) {
            require_logout();
            $loginpage = new \moodle_url("/login/index.php");
            $result['redirecturl'] = $loginpage->out();
        }
        // Log dismissed event.
        $params = array(
            'context' => \context_system::instance(),
            'objectid' => $noticeid,
            'relateduserid' => $USER->id,
        );
        $event = \local_sitenotice\event\sitenotice_dismissed::create($params);
        $event->trigger();

        $result = array();
        $result['status'] = true;
        return $result;
    }

    public static function acknowledge_notice($noticeid) {
        global $USER, $DB;
        $USER->viewednotices[$noticeid] = time();

        // Log acknowledged event.
        $params = array(
            'context' => \context_system::instance(),
            'objectid' => $noticeid,
            'relateduserid' => $USER->id,
        );
        $event = \local_sitenotice\event\sitenotice_acknowledged::create($params);
        $event->trigger();

        // Acknowledgement Record.
        $notice = self::retrieve_notice($noticeid);
        $record = new \stdClass();
        $record->userid = $USER->id;
        $record->username = $USER->username;
        $record->firstname = $USER->firstname;
        $record->lastname = $USER->lastname;
        $record->idnumber = $USER->idnumber;
        $record->noticeid = $noticeid;
        $record->noticetitle = $notice->title;
        $record->timecreated = time();
        $DB->insert_record('local_sitenotice_ack', $record);

        $result = array();
        $result['status'] = true;
        return $result;
    }

    public static function track_link($linkid) {
        global $DB, $USER;
        $record = $DB->get_record('local_sitenotice_hlinks_his', [ 'userid' => $USER->id, 'hlinkid' => $linkid]);
        if (empty($record)) {
            $record = new \stdClass();
            $record->hlinkid = $linkid;
            $record->userid = $USER->id;
            $record->timeclicked = 1;
            $DB->insert_record('local_sitenotice_hlinks_his', $record);
        } else {
            $record->timeclicked += 1;
            $DB->update_record('local_sitenotice_hlinks_his', $record);
        }

        $result = array();
        $result['status'] = true;
        return $result;
    }
}
