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
 * Quiz attempt walk through using data from csv file.
 *
 * @package    mod_quiz
 * @category   phpunit
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/editlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Quiz attempt walk through using data from csv file.
 *
 * @package    mod_quiz
 * @category   phpunit
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quiz_attempt_walkthrough_from_csv_testcase extends advanced_testcase {

    /**
     * @var array postfix number for sets of csv files to load data from.
     */
    protected $tests = array('00');

    /**
     * @var stdClass the quiz record we create.
     */
    protected $quiz;

    /**
     * @var array with slot no => question name => questionid. Question ids of questions created in the same category as random q.
     */
    protected $randqids;

    public function create_quiz($qs) {
        global $SITE, $DB;
        $this->setAdminUser();

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $slots = array();
        $qidsbycat = array();
        $sumofgrades = 0;
        for ($rowno = 0; $rowno < $qs->getRowCount(); $rowno++) {
            $q = $this->explode_dot_separated_keys_to_make_subindexs($qs->getRow($rowno));

            $catname = array('name' => $q['cat']);
            if (!$cat = $DB->get_record('question_categories', array('name' => $q['cat']))) {
                $cat = $questiongenerator->create_question_category($catname);
            }
            $q['catid'] = $cat->id;
            foreach (array('which' => null, 'overrides' => array()) as $key => $default) {
                if (empty($q[$key])) {
                    $q[$key] = $default;
                }
            }

            if ($q['type'] !== 'random') {
                // Don't actually create random questions here.
                $overrides = array('category' => $cat->id, 'defaultmark' => $q['mark']) + $q['overrides'];
                $question = $questiongenerator->create_question($q['type'], $q['which'], $overrides);
                $q['id'] = $question->id;

                if (!isset($qidsbycat[$q['cat']])) {
                    $qidsbycat[$q['cat']] = array();
                }
                if (!empty($q['which'])) {
                    $name = $q['type'].'_'.$q['which'];
                } else {
                    $name = $q['type'];
                }
                $qidsbycat[$q['catid']][$name] = $q['id'];
            }
            if (!empty($q['slot'])) {
                $slots[$q['slot']] = $q;
                $sumofgrades += $q['mark'];
            }
        }

        ksort($slots);

        // Make a quiz.
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $this->quiz = $quizgenerator->create_instance(array('course'=>$SITE->id,
                                                      'questionsperpage' => 0,
                                                      'grade' => 100.0,
                                                      'sumgrades' => $sumofgrades));

        $this->randqids = array();
        foreach ($slots as $slotno => $slotquestion) {
            if ($slotquestion['type'] !== 'random') {
                quiz_add_quiz_question($slotquestion['id'], $this->quiz, 0);
                // Setting default mark above does not affect the grade for multi-answer question type (and maybe others??).
                // Set the mark again just to be sure.
                quiz_update_question_instance($slotquestion['mark'], $slotquestion['id'], $this->quiz);
            } else {
                quiz_add_random_questions($this->quiz, 0, $slotquestion['catid'], 1, 0);
                $this->randqids[$slotno] = $qidsbycat[$slotquestion['catid']];
            }
        }
    }

    public function get_data_for_walkthrough() {
        $dataset = array();
        foreach ($this->tests as $test) {
            $qs = $this->load_csv_data_file('questions', $test);
            $steps = $this->load_csv_data_file('steps', $test);
            $dataset[] = array($qs, $steps);
        }
        return $dataset;
    }

    /**
     * Get full path of CSV file.
     *
     * @param string $setname
     * @param string $test
     * @return string full path of file.
     */
    protected function get_full_path_of_csv_file($setname, $test) {
        return  __DIR__."/fixtures/{$setname}{$test}.csv";
    }

    /**
     * Load dataset from CSV file "{$setname}{$test}.csv".
     *
     * @param string $setname
     * @param string $test
     * @return \PHPUnit_Extensions_Database_DataSet_ITable
     */
    protected function load_csv_data_file($setname, $test) {
        $files = array($setname => $this->get_full_path_of_csv_file($setname, $test));
        return $this->createCsvDataSet($files)->getTable($setname);
    }

    /**
     * Create a quiz add questions to it, walk through quiz attempts and then check results.
     *
     * @param PHPUnit_Extensions_Database_DataSet_ITable $qs questions to add to quiz, read from csv file "questionsXX.csv".
     * @param PHPUnit_Extensions_Database_DataSet_ITable $steps steps to simulate, read from csv file "stepsXX.csv".
     * @dataProvider get_data_for_walkthrough
     */
    public function test_walkthrough_from_csv($qs, $steps) {
        global $DB;
        $this->resetAfterTest(true);
        question_bank::get_qtype('random')->clear_caches_before_testing();

        $this->create_quiz($qs);

        for ($rowno = 0; $rowno < $steps->getRowCount(); $rowno++) {

            $step = $this->explode_dot_separated_keys_to_make_subindexs($steps->getRow($rowno));
            // Find existing user or make a new user to do the quiz.
            $username = array('firstname' => $step['firstname'],
                              'lastname' => $step['lastname']);

            if (!$user = $DB->get_record('user', $username)) {
                $user = $this->getDataGenerator()->create_user($username);
            }
            $this->setUser($user);
            // Start the attempt.
            $quizobj = quiz::create($this->quiz->id, $user->id);
            $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
            $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

            $timenow = time();
            $attempt = quiz_create_attempt($quizobj, 1, false, $timenow);
            // Select variant and / or random sub question.
            if (!isset($step['variants'])) {
                $step['variants'] = array();
            }
            if (isset($step['randqs'])) {
                // Replace 'names' with ids.
                foreach ($step['randqs'] as $slotno => $randqname) {
                    $step['randqs'][$slotno] = $this->randqids[$slotno][$randqname];
                }
            } else {
                $step['randqs'] = array();
            }
            quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow, $step['randqs'], $step['variants']);
            quiz_attempt_save_started($quizobj, $quba, $attempt);

            // Process some responses from the student.
            $attemptobj = quiz_attempt::create($attempt->id);
            $attemptobj->process_submitted_actions($timenow, false, $step['responses']);

            // Finish the attempt.
            $attemptobj = quiz_attempt::create($attempt->id);
            $attemptobj->process_finish($timenow, false);

            // Re-load quiz attempt data.
            $attemptobj = quiz_attempt::create($attempt->id);

            // Check that results are stored as expected.
            $this->assertEquals(1, $attemptobj->get_attempt_number());
            $this->assertEquals(true, $attemptobj->is_finished());
            $this->assertEquals($timenow, $attemptobj->get_submitted_date());
            $this->assertEquals($user->id, $attemptobj->get_userid());

            // Check quiz grades.
            $grades = quiz_get_user_grades($this->quiz, $user->id);
            $grade = array_shift($grades);
            $this->assertEquals(100.0, $grade->rawgrade);

            // Check grade book.
            $gradebookgrades = grade_get_grades($attemptobj->get_courseid(), 'mod', 'quiz', $this->quiz->id, $user->id);
            $gradebookitem = array_shift($gradebookgrades->items);
            $gradebookgrade = array_shift($gradebookitem->grades);
            $this->assertEquals(100, $gradebookgrade->grade);
        }
    }

    /**
     * Break down row of csv data into sub arrays, according to column names.
     *
     * @param array $row from csv file with field names with parts separate by '.'.
     * @return array the row with each part of the field name following a '.' being a separate sub array's index.
     */
    protected function explode_dot_separated_keys_to_make_subindexs(array $row) {
        $parts = array();
        foreach ($row as $columnkey => $value) {
            $newkeys = explode('.', trim($columnkey));
            $placetoputvalue =& $parts;
            foreach ($newkeys as $newkeydepth => $newkey) {
                if ($newkeydepth + 1 === count($newkeys)) {
                    $placetoputvalue[$newkey] = $value;
                } else {
                    // Going deeper down.
                    if (!isset($placetoputvalue[$newkey])) {
                        $placetoputvalue[$newkey] = array();
                    }
                    $placetoputvalue =& $placetoputvalue[$newkey];
                }
            }
        }
        return $parts;
    }
}