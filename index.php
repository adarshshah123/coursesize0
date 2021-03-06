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
 * Version information
 *
 * @package    report_coursesize0
 * @copyright  2014 Catalyst IT {@link http://www.catalyst.net.nz}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/csvlib.class.php');

admin_externalpage_setup('reportcoursesize0');

// Dirty hack to filter by coursecategory - not very efficient.
$coursecategory = optional_param('category', '', PARAM_INT);
$download = optional_param('download', '', PARAM_INT);

// If we should show or hide empty courses.
if (!defined('REPORT_COURSESIZE_SHOWEMPTYCOURSES')) {
    define('REPORT_COURSESIZE_SHOWEMPTYCOURSES', false);
}
// How many users should we show in the User list.
if (!defined('REPORT_COURSESIZE_NUMBEROFUSERS')) {
    define('REPORT_COURSESIZE_NUMBEROFUSERS', 10);
}
// How often should we update the total sitedata usage.
if (!defined('REPORT_COURSESIZE_UPDATETOTAL')) {
    define('REPORT_COURSESIZE_UPDATETOTAL', 1 * DAYSECS);
}

$reportconfig = get_config('report_coursesize0');
if (!empty($reportconfig->filessize) && !empty($reportconfig->filessizeupdated)
    && ($reportconfig->filessizeupdated > time() - REPORT_COURSESIZE_UPDATETOTAL)) {
    // Total files usage has been recently calculated, and stored by another process - use that.
    $totalusage = $reportconfig->filessize;
    $totaldate = date("Y-m-d H:i", $reportconfig->filessizeupdated);
} else {
    // Total files usage either hasn't been stored, or is out of date.
    $totaldate = date("Y-m-d H:i", time());
    $totalusage = get_directory_size($CFG->dataroot);
    set_config('filessize', $totalusage, 'report_coursesize0');
    set_config('filessizeupdated', time(), 'report_coursesize0');
}

$totalusagereadable = number_format(ceil($totalusage / 1000000 )) . " MB";

// TODO: display the sizes of directories (other than filedir) in dataroot
// eg old 1.9 course dirs, temp, sessions etc.

// Generate a full list of context sitedata usage stats.
$subsql = 'SELECT f.contextid, sum(f.filesize) as filessize' .
          ' FROM {files} f';
$wherebackup = ' WHERE component like \'backup\' AND referencefileid IS NULL';
$groupby = ' GROUP BY f.contextid';
$reverse = 'reverse(cx2.path)';
$poslast = $DB->sql_position("'/'", $reverse);
$length = $DB->sql_length('cx2.path');
$substr = $DB->sql_substr('cx2.path', 1, $length ." - " . $poslast);
$likestr = $DB->sql_concat($substr, "'%'");

$sizesql = 'SELECT cx.id, cx.contextlevel, cx.instanceid, cx.path, cx.depth,
            size.filessize, backupsize.filessize as backupsize' .
           ' FROM {context} cx ' .
           ' INNER JOIN ( ' . $subsql . $groupby . ' ) size on cx.id=size.contextid' .
           ' LEFT JOIN ( ' . $subsql . $wherebackup . $groupby . ' ) backupsize on cx.id=backupsize.contextid' .
           ' ORDER by cx.depth ASC, cx.path ASC';
$cxsizes = $DB->get_recordset_sql($sizesql);
$coursesizes = array(); // To track a mapping of courseid to filessize.
$coursebackupsizes = array(); // To track a mapping of courseid to backup filessize.
$usersizes = array(); // To track a mapping of users to filesize.
$systemsize = $systembackupsize = 0;


// This seems like an in-efficient method to filter by course categories as we are not excluding them from the main list.
$coursesql = 'SELECT cx.id, c.id as courseid ' .
    'FROM {course} c ' .
    ' INNER JOIN {context} cx ON cx.instanceid=c.id AND cx.contextlevel = ' . CONTEXT_COURSE;
$params = array();
$courseparams = array();
$extracoursesql = '';
if (!empty($coursecategory)) {
    $context = context_coursecat::instance($coursecategory);
    $coursecat = core_course_category::get($coursecategory);
    $courses = $coursecat->get_courses(array('recursive' => true, 'idonly' => true));

    if (!empty($courses)) {
        list($insql, $courseparams) = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED);
        $extracoursesql = ' WHERE c.id ' . $insql;
    } else {
        // Don't show any courses if category is selected but category has no courses.
        // This stuff really needs a rewrite!
        $extracoursesql = ' WHERE c.id is null';
    }
}
$coursesql .= $extracoursesql;
$params = array_merge($params, $courseparams);
$courselookup = $DB->get_records_sql($coursesql, $params);

foreach ($cxsizes as $cxdata) {
    $contextlevel = $cxdata->contextlevel;
    $instanceid = $cxdata->instanceid;
    $contextsize = $cxdata->filessize;
    $contextbackupsize = (empty($cxdata->backupsize) ? 0 : $cxdata->backupsize);
    if ($contextlevel == CONTEXT_USER) {
        $usersizes[$instanceid] = $contextsize;
        $userbackupsizes[$instanceid] = $contextbackupsize;
        continue;
    }
    if ($contextlevel == CONTEXT_COURSE) {
        $coursesizes[$instanceid] = $contextsize;
        $coursebackupsizes[$instanceid] = $contextbackupsize;
        continue;
    }
    if (($contextlevel == CONTEXT_SYSTEM) || ($contextlevel == CONTEXT_COURSECAT)) {
        $systemsize = $contextsize;
        $systembackupsize = $contextbackupsize;
        continue;
    }
    // Not a course, user, system, category, see it it's something that should be listed under a course
    // Modules & Blocks mostly.
    $path = explode('/', $cxdata->path);
    array_shift($path); // Get rid of the leading (empty) array item.
    array_pop($path); // Trim the contextid of the current context itself.

    $success = false; // Course not yet found.
    // Look up through the parent contexts of this item until a course is found.
    while (count($path)) {
        $contextid = array_pop($path);
        if (isset($courselookup[$contextid])) {
            $success = true; // Course found.
            // Record the files for the current context against the course.
            $courseid = $courselookup[$contextid]->courseid;
            if (!empty($coursesizes[$courseid])) {
                $coursesizes[$courseid] += $contextsize;
                $coursebackupsizes[$courseid] += $contextbackupsize;
            } else {
                $coursesizes[$courseid] = $contextsize;
                $coursebackupsizes[$courseid] = $contextbackupsize;
            }
            break;
        }
    }
    if (!$success) {
        // Didn't find a course
        // A module or block not under a course?
        $systemsize += $contextsize;
        $systembackupsize += $contextbackupsize;
    }
}
$cxsizes->close();
$sql = "SELECT c.id, c.shortname, c.category, ca.name FROM {course} c "
       ."JOIN {course_categories} ca on c.category = ca.id".$extracoursesql;
$courses = $DB->get_records_sql($sql, $courseparams);

$coursetable = new html_table();
$coursetable->align = array('right', 'right', 'left');
$coursetable->head = array(get_string('course'),
                           get_string('category'),
                           get_string('diskusage', 'report_coursesize0'),
                           get_string('backupsize', 'report_coursesize0'));
$coursetable->data = array();

arsort($coursesizes);
$totalsize = 0;
$totalbackupsize = 0;
$downloaddata = array();
$downloaddata[] = array(get_string('course'),
                           get_string('category'),
                           get_string('diskusage', 'report_coursesize0'),
                           get_string('backupsize', 'report_coursesize0'));;
foreach ($coursesizes as $courseid => $size) {
    if (empty($courses[$courseid])) {
        continue;
    }
    $backupsize = $coursebackupsizes[$courseid];
    $totalsize = $totalsize + $size;
    $totalbackupsize  = $totalbackupsize + $backupsize;
    $course = $courses[$courseid];
    $row = array();
    $row[] = '<a href="'.$CFG->wwwroot.'/course/view.php?id='.$course->id.'">' . $course->shortname . '</a>';
    $row[] = '<a href="'.$CFG->wwwroot.'/course/index.php?categoryid='.$course->category.'">' . $course->name . '</a>';

    $readablesize = number_format(ceil($size / 1000000 )) . "MB";
    $a = new stdClass;
    $a->bytes = $size;
    $a->shortname = $course->shortname;
    $a->backupbytes = $backupsize;
    $bytesused = get_string('coursebytes', 'report_coursesize0', $a);
    $backupbytesused = get_string('coursebackupbytes', 'report_coursesize0', $a);
    $summarylink = new moodle_url('/report/coursesize0/course.php', array('id' => $course->id));
    $summary = html_writer::link($summarylink, get_string('coursesummary', 'report_coursesize0'));
    $row[] = "<span id=\"coursesize_".$course->shortname."\" title=\"$bytesused\">$readablesize</span>".$summary;
    $row[] = "<span title=\"$backupbytesused\">" . number_format(ceil($backupsize / 1000000)) . " MB</span>";
    $coursetable->data[] = $row;
    $downloaddata[] = array($course->shortname, $course->name, str_replace(',', '', $readablesize),
                            str_replace(',', '', number_format(ceil($backupsize / 1000000)) . "MB"));
    unset($courses[$courseid]);
}

// Now add the courses that had no sitedata into the table.
if (REPORT_COURSESIZE_SHOWEMPTYCOURSES) {
    $a = new stdClass;
    $a->bytes = 0;
    $a->backupbytes = 0;
    foreach ($courses as $cid => $course) {
        $a->shortname = $course->shortname;
        $bytesused = get_string('coursebytes', 'report_coursesize0', $a);
        $bytesused = get_string('coursebackupbytes', 'report_coursesize0', $a);
        $row = array();
        $row[] = '<a href="'.$CFG->wwwroot.'/course/view.php?id='.$course->id.'">' . $course->shortname . '</a>';
        $row[] = "<span title=\"$bytesused\">0 MB</span>";
        $row[] = "<span title=\"$bytesused\">0 MB</span>";
        $coursetable->data[] = $row;
    }
}
// Now add the totals to the bottom of the table.
$coursetable->data[] = array(); // Add empty row before total.
$downloaddata[] = array();
$row = array();
$row[] = get_string('total');
$row[] = '';
$row[] = number_format(ceil($totalsize/ 1000000 )) . "MB";
$row[] = number_format(ceil($totalbackupsize / 1000000 )) . "MB";
$coursetable->data[] = $row;
$downloaddata[] = array(get_string('total'), '', str_replace(',', '', number_format(ceil($totalsize / 1000000 ))) .
                        "MB", str_replace(',', '', number_format(ceil($totalbackupsize / 1000000 )) . "MB"));
unset($courses);


if (!empty($usersizes)) {
    arsort($usersizes);
    $usertable = new html_table();
    $usertable->align = array('right', 'right');
    $usertable->head = array(get_string('user'), get_string('diskusage', 'report_coursesize0'));
    $usertable->data = array();
    $usercount = 0;
    foreach ($usersizes as $userid => $size) {
        $usercount++;
        $user = $DB->get_record('user', array('id' => $userid));
        $row = array();
        $row[] = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$userid.'">' . fullname($user) . '</a>';
        $row[] = number_format(ceil($size  / 1000000)) . "MB";
        $usertable->data[] = $row;
        if ($usercount >= REPORT_COURSESIZE_NUMBEROFUSERS) {
            break;
        }
    }
    unset($users);
}
$systemsizereadable = number_format(ceil($systemsize / 1000000)) . "MB";
$systembackupreadable = number_format(ceil($systembackupsize / 1000000)) . "MB";


// Add in Course Cat including dropdown to filter.

$url = '';
$catlookup = $DB->get_records_sql('select id,name from {course_categories}');
$options = array('0' => 'All Courses' );
foreach ($catlookup as $cat) {
    $options[$cat->id] = $cat->name;
}

// Add in download option. Exports CSV.

if ($download == 1) {
    $downloadfilename = clean_filename ( "export_csv" );
    $csvexport = new csv_export_writer ( 'commer' );
    $csvexport->set_filename ( $downloadfilename );
    foreach ($downloaddata as $data) {
        $csvexport->add_data ($data);
    }
    $csvexport->download_file ();
}

// All the processing done, the rest is just output stuff.

print $OUTPUT->header();
if (empty($coursecat)) {
    print $OUTPUT->heading(get_string("sitefilesusage", 'report_coursesize0'));
    print '<strong>' . get_string("totalsitedata", 'report_coursesize0', $totalusagereadable) . '</strong> ';
    print get_string("sizerecorded", "report_coursesize0", $totaldate) . "<br/><br/>\n";
    print get_string('catsystemuse', 'report_coursesize0', $systemsizereadable) . "<br/>";
    print get_string('catsystembackupuse', 'report_coursesize0', $systembackupreadable) . "<br/>";
    if (!empty($CFG->filessizelimit)) {
        print get_string("sizepermitted", 'report_coursesize0', number_format($CFG->filessizelimit)) . "<br/>\n";
    }
}
$heading = get_string('coursesize', 'report_coursesize0');
if (!empty($coursecat)) {
    $heading .= " - ".$coursecat->name;
}
print $OUTPUT->heading($heading);

$desc = get_string('coursesize_desc', 'report_coursesize0');

if (!REPORT_COURSESIZE_SHOWEMPTYCOURSES) {
    $desc .= ' '. get_string('emptycourseshidden', 'report_coursesize0');
}
print $OUTPUT->box($desc);

$filter = $OUTPUT->single_select($url, 'category', $options);
$filter .= $OUTPUT->single_button(new moodle_url('index.php', array('download' => 1, 'category' => $coursecategory )),
                                  get_string('exportcsv', 'report_coursesize0'), 'post', ['class' => 'coursesizedownload']);

print $OUTPUT->box($filter)."<br/>";

print html_writer::table($coursetable);

if (empty($coursecat)) {
    print $OUTPUT->heading(get_string('userstopnum', 'report_coursesize0', REPORT_COURSESIZE_NUMBEROFUSERS));

    if (!isset($usertable)) {
        print get_string('nouserfiles', 'report_coursesize0');
    } else {
        print html_writer::table($usertable);
    }
}

print $OUTPUT->footer();
