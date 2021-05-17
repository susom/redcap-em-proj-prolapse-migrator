<?php

/** @var MappedRow mrow */

namespace Stanford\ProjProlapseMigrator;

include_once "emLoggerTrait.php";
include_once "EMConfigurationException.php";
require_once "classes/RepeatingForms.php";
include_once 'classes/Mapper.php';
include_once 'classes/DDMigrator.php';
include_once 'classes/MappedRow.php';
include_once 'classes/Transmogrifier.php';

use REDCap;
use Exception;
use Survey;

class ProjProlapseMigrator extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $mapper;

    public function dumpMap($file, $origin_pid) {
        $this->emDebug("Starting Map Dump");

        //test PDF
        //Survey::archiveResponseAsPDF('111-0011-01','1776','consent_for_child_healthy', 1);

        //exit;

        //upload csv file that defines the mapping from old field to new field
        //$this->mapper = new Mapper($this->getProjectSetting('origin-pid'), $file);
        $mapper = new Mapper($origin_pid, $file);

        $mapper->downloadCSVFile();
        //$mapper->printDictionary(); exit;

    }

    public  function processOneRecord($file, $origin_pid, $record_id) {
        $origin_main_event = $this->getProjectSetting('origin-main-event');

        // 1. get the list of record ids from project 1
        $this->emDebug("About to get single record $record_id");
        $record_list[] = $record_id;

        //there seems to be an issue with getdata running into PHP Fatal error:  Allowed memory size of 2147483648 bytes exhausted
        $params = array(
            'project_id'   => $origin_pid,
            'return_format' => 'array',
            'events'        => array($origin_main_event),
            'records'       => $record_list,
            'fields'        => null
        );
        $data = REDCap::getData($params);

        $this->process($origin_pid, $data, $file);


    }

    public  function processRecords($file, $origin_pid, $first_ct = 0, $last_ct = null) {

        $origin_main_event = $this->getProjectSetting('origin-main-event');

        // 1. get the list of record ids from project 1
        $this->emDebug("About to get record from count $first_ct to $last_ct");
        $record_list = array();

        //failing due to memory size.  will try restricting records according to chunks from first to last_ct
        $r_params = array(
            'project_id'    => $origin_pid,
            'return_format' => 'json',
            'events'        => array($origin_main_event),
            'fields'        => array($this->getProjectSetting('origin-main-id'))
        );
        $r_json_data = REDCap::getData($r_params);
        $r_data = json_decode($r_json_data, true);

        for ($i = $first_ct; $i <= $last_ct; $i++) {
            $rec_id = $r_data[$i][$this->getProjectSetting('origin-main-id')];
            $this->emDebug("adding ct: $i record_id : $rec_id");
            $record_list[] = $rec_id;
        }

        //there seems to be an issue with getdata running into PHP Fatal error:  Allowed memory size of 2147483648 bytes exhausted
        $params = array(
            'project_id'   => $origin_pid,
            'return_format' => 'array',
            'events'        => array($origin_main_event),
            'records'       => $record_list,
            'fields'        => null
        );
        $data = REDCap::getData($params);

        $this->process($origin_pid, $data, $file);
    }


    /**
     * Data
     *
     * @param $file
     * @param $origin_pid
     * @param int $first_ct
     * @param null $test_ct
     * @throws Exception
     */
    public function process($origin_pid,$data, $file) {

        $target_visit_event = $this->getProjectSetting('visit-event-id') ;
        $target_main_event = $this->getProjectSetting('main-config-event-id');
        //$origin_pid =  $this->getProjectSetting('origin-pid'); //"233";
        $origin_main_event = $this->getProjectSetting('origin-main-event');

        $not_entered = array();
        $data_invalid = array();

        $this->emDebug("Starting Migration");

        //upload csv file that defines the mapping from old field to new field
        //$this->mapper = new Mapper($this->getProjectSetting('origin-pid'), $file);
        $this->mapper = new Mapper($origin_pid, $file);
        $transmogrifier = new Transmogrifier($this->mapper->getMapper());

        //$this->mapper->printDictionary(); exit;

        //change May 14: decided not to make episodes into repeating forms
        // Create the RepeatingForms for the project
        //name as 'rf_' + name of form (used in excel file to create variable name
        foreach ($this->mapper->getRepeatingForms() as $r_form) {
            ${"rf_" . $r_form} = RepeatingForms::byForm($this->getProjectId(), $r_form);
        }

        //Repeating Event for visits
        $rf_event = RepeatingForms::byEvent($this->getProjectId(),$target_visit_event );


        //$data = REDCap::getData($origin_pid, 'array', null, null, array($origin_main_event));
        $ctr = 0;

        // foreach row in first event
        foreach($data as $record => $events) {

            echo "<br> Analyzing row $ctr: RECORD: $record ";
            $this->emDebug(" Analyzing row $ctr: RECORD: $record EVENT: $events");

            foreach($events as $event => $row) {

                //if event is 'repeat_instances' IGNORE (not used according to bgurland
                if ($event == 'repeat_instances') {
                    $this->emDebug("IGNORING REPEAT INSTANCES from array IN EVENT $event");
                    continue;
                }

                $this->emDebug(" Analyzing row $ctr: RECORD: $record EVENT: $event");

                //check that the ID doesn't already exist

                $origin_id_field = $this->getProjectSetting('origin-main-id');
                $mrn_field       = $this->getProjectSetting('target-mrn-field');

                try {
                    $mrow = new MappedRow($ctr, $row, $origin_id_field, $mrn_field, $this->mapper->getMapper(), $transmogrifier);
                    if (!empty($mrow->getDataError())) {
                        $data_invalid[$record] = $mrow->getDataError();
                        $this->emError($mrow->getDataError());
                    }
                } catch (EMConfigurationException $ece) {
                    $msg = 'Unable to process row $ctr: ' . $ece->getMessage();
                    $this->emError($msg);
                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
                    die ($msg);  // EM config is not set properly. Just abandon ship;'
                } catch (Exception $e) {
                    $msg = 'Unable to process row $ctr: ' . $e->getMessage();
                    $this->emError($msg);
                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
                    continue;
                }

//                if ($mrow->processRow() === FALSE) {
//                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
//                };


                //check whether the id_pans id (ex: 77-0148) already exists in which case only handle the visit portion of the row
                //$filter = "[" . REDCap::getEventNames(true, false,$target_main_event) . "][" . $target_id . "] = '$id'";
                //xxyjl $found = $this->checkIDExists($id, $target_id,$target_main_event);
                try {
                    $found = $mrow->checkIDExistsInMain();
                } catch (\Exception $e) {
                    $msg = 'Unable to process row $ctr: '. $e->getMessage();
                    $this->emError($msg);
                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
                    continue;
                }

                //Set the participant ID
                if (empty($found)) {
                    $this->emDEbug("Row $ctr: EMPTY: $record NOT FOUND so proceed with migration");
                    //not found so create a new record ID

                    //reuse the same id
                    //$record_id = $this->getNextId($target_main_event, "S", 4);
                    $record_id = $record; //reuse old record
                    $this->emDebug("Row $ctr: Starting migration of $record to new id: $record_id");

                //HANDLE MAIN EVENT DATA
                $main_data = $mrow->getMainData();
                if (null !== ($mrow->getMainData())) {
                    //save the main event data
                    //$return = REDCap::saveData('json', json_encode(array($main_data)));
                    //RepeatingForms uses array. i think expected format is [id][event] = $data
                    $temp_instance = array();  //reset to empty
                    $temp_instance[$record_id][$this->getProjectSetting('main-config-event-id')] = $main_data;

                    $return = REDCap::saveData('array', $temp_instance);

                    if (isset($return["errors"]) and !empty($return["errors"])) {
                        $msg = "Row $ctr: Not able to save project data for record $record_id with original id: " . $mrow->getOriginalID() . implode(" / ", $return['errors']);
                        $this->emError($msg, $return['errors']);//, $temp_instance);
                        $this->logProblemRow($ctr, $row, $msg, $not_entered);
                    } else {
                        $this->emLog("Row $ctr: Successfully saved main event data for record " . $mrow->getOriginalID() . " with new id $record_id");
                    }
                }

                //HANDLE EVENT DATA
                $event_data = $mrow->getEventData();
                if (null !== $event_data) {
                    $save_event_data = array(); //reset to empty
                    $save_event_data[$record_id] = $event_data;

                    $this->emDebug("Row $ctr EVENT: Starting Event migration w count of " . sizeof($event_data)); //, $mrow->getVisitData());

                    $event_save_status = REDCap::saveData('array',$save_event_data);
                    if (isset($event_save_status["errors"]) and !empty($event_save_status["errors"])) {
                        $msg = "Row $ctr: Not able to save event data for record $record_id  with original id: " . $mrow->getOriginalID() . implode(" / ", $event_save_status['errors']);
                        $this->emError($msg, $event_save_status['errors'], $save_event_data);
                        $this->logProblemRow($ctr, $row, $msg, $not_entered);
                        break;
                    } else {
                        $this->emLog("Row $ctr: Successfully saved main event data for record " . $mrow->getOriginalID() . " with new id $record_id");
                    }

                }


                //if there is visit data
                $repeat_data = $mrow->getVisitData();
                if (null !== $repeat_data) {
                    $this->emDebug("Row $ctr: Starting Repeating Event migration w count of " . sizeof($mrow->getVisitData())); //, $mrow->getVisitData());

                    foreach ($repeat_data as $v_event => $v_instances  ) {
                        foreach ($v_instances as $v_instance => $v_data ) {
                            $v_event_id = REDCap::getEventIdFromUniqueEvent($v_event);

                            if (empty($v_event_id)) {
                                $msg = "Row $ctr: EVENT ID was not found: $v_event_id from event name $v_event";
                                $this->emError($msg);
                                $this->logProblemRow($ctr, $row, $msg, $not_entered);
                                continue;
                            }

                            //bug where getNextinstanceId is returning stale values.
                            //$next_instance_orig = $rf_event->getNextInstanceId($record_id, $v_event_id);
                            //$this->emDebug("Start getDAta nextInstance");
                            //$next_instance = $rf_event->getNextInstanceIDForceReload($record_id, $v_event_id);
                            //Switched to using SQL
                            //$next_instance = $rf_event->getNextInstanceIDSQL($record_id, $v_event_id);
                            //just use the hardcoded instance in the map
                            $next_instance = $v_instance;


                            $status = $rf_event->saveInstance($record_id, $v_data, $v_instance, $v_event_id);
                            $this->emDebug("Row $ctr: record:" . $mrow->getOriginalID() . " REPEATING EVENT: $v_event Next instance is $v_instance in event $v_event_id and status is  $status"); //, $v_data);
                            if (($status === false) && $rf_event->last_error_message) {
                                $this->emError("Row $ctr: There was an error saving record $record_id: in event <$v_event_id>", $rf_event->last_error_message);
                                $this->logProblemRow($ctr, $row, $rf_event->last_error_message, $not_entered);

                            }
                        }

                    }
                } else {
                            $msg = "Row $ctr: REPEAT Event had no data to enter for " . $mrow->getOriginalID();
                            $this->emError($msg);
                            $this->logProblemRow($ctr, $row, $msg, $not_entered);
                }


                        //HANDLE REPEATING FORM DATA
                        //I"m making an assumption here that there will be no repeating form data without a visit data

                        //save the repeat form
                        //$this->emDebug("Row $ctr: Starting Repeat Form migration w count of " . sizeof($mrow->getRepeatFormData()), $mrow->getRepeatFormData());

                //xxyjl todo: check if the visit_id already exists?
                foreach ($mrow->getRepeatFormData() as $form_name => $instances) {
                            $this->emDebug("Repeat Form instrument $form_name ");
                            foreach ($instances as $form_instance => $form_data) {
                                $rf_form = ${"rf_" . $form_name};

                                $next_instance = $rf_form->getNextInstanceId($record_id, $target_main_event);
                                $this->emDebug("Row $ctr: Working on $form_name with $rf_form on instance number " . $form_instance . " Adding as $next_instance");

                                $rf_form->saveInstance($record_id, $form_data, $next_instance, $target_main_event);

                                //if ($rf_form->last_error_message) {
                                if ($rf_form === false) {
                                    $this->emError("Row $ctr: There was an error: ", $rf_form->last_error_message);
                                    $this->logProblemRow($ctr, $row, $rf_form->last_error_message, $not_entered);
                                }
                            }
                }

                } else {
                    //$this->emDEbug("FOUND", $found);
                    //id is  found (already exists), so only add as a visit.
                    //now check that visit ID already doesn't exist

                    //$record_id = $found[0][REDCap::getRecordIdField()];
                    $record_id = $found['record']; //with the new SQL version
                    $this->emDEbug("Row $ctr: Found an EXISTING record ($record_id) with count " . count($row));
                    $msg =  "NOT LOADING: Found an EXISTING record ($record_id) with count " . count($row);
                    $this->emError($msg);
                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
                }

            $ctr++;
            unset($mrow);
            }
        }

        if (!empty($not_entered)) {
            $this->emDEbug("NOT ENTERED: ".json_encode($not_entered));

            echo "<br>PROBLEM ROWS: <pre>";
            print_r($not_entered);
            echo "</pre>";
        }
        if (!empty($data_invalid)) {
            $this->emDebug("INVALID DATA: " . json_encode($data_invalid));
            echo "<br>INVALID DATA: <pre>";
            print_r($data_invalid);
            echo "</pre>";
        }
        //printout the error file
        //file_put_contents("foo.csv", $not_entered);


        //exit;



        //$this->downloadCSVFile("troublerows.csv",$not_entered);

    }


    public function migrateDataDictionary($file, $origin_pid) {


        $dd_mig = new DDMigrator($file, $origin_pid);

        //pass on the original data dictionary
        $dd_mig->updateDDFromOriginal();
        //$dd_mig->print(100);
    }

    function logProblemRow($ctr, $row, $msg, &$not_entered)  {
        //$msg = " VISIT FOUND with instance ID: " . $found_event_instance_id . " NOT entering data.";

        $not_entered[$ctr]['reason'] = $msg;
        //$not_entered[$ctr]['data'] = $row;  //probably should implode it.  have to handle checkboxes first



    }


    function getNextId( $event_id, $prefix = '', $padding = false) {
        $id_field = REDCap::getRecordIdField();
        $q = REDCap::getData($this->getProjectId(),'array',NULL,array($id_field), $event_id);


        $i = 1;
        do {
            // Make a padded number
            if ($padding) {
                // make sure we haven't exceeded padding, pad of 2 means
                $max = 10 ** $padding;
                if ($i >= $max) {
                    $this->emError("Error - $i exceeds max of $max permitted by padding of $padding characters");
                    return false;
                }
                $id = str_pad($i, $padding, "0", STR_PAD_LEFT);
            } else {
                $id = $i;
            }

            // Add the prefix
            $id = $prefix . $id;

            $i++;
        } while (!empty($q[$id][$event_id][$id_field]));

        return $id;
    }

}