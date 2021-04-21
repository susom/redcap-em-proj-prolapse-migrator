<?php

namespace Stanford\ProjProlapseMigrator;

/** @var \Stanford\ProjProlapseMigrator\ProjProlapseMigrator $module */

use REDCap;

include_once 'Mapper.php';

class DDMigrator
{

    public $data_dict;
    public $from_data_dict;

    private $data_dict2;
    private $to_data_dict;
    private $mapper;
    private $map;
    private $map_mid;


    private $repeating_forms;
    private $header;
    private $transmogrifier;

    private $reg_pattern;
    private $reg_replace;

    /**
     * [treatment_other_ep1] => Array (
     *      [from_field] => treatment_other_ep1
     * [to_repeat_event] =>
     * [to_form_instance] => episode:1
     * [to_field] => ep_treatment_other
     * [custom] =>
     * [form_name] =>
     * [notes] =>
     * [from_fieldtype] => notes
     * [from_form] => pans_patient_questionnaire
     */


    /**
     *
     * Mapper constructor.
     * @param $origin_pid
     * @param $file
     *
     */
    public function __construct($file,$origin_id)
    {
        global $module;



        //or origin_id????
        //$this->mapper = new Mapper($module->getProjectId(), $file);
        $this->mapper = new Mapper($origin_id, $file);


        $this->map = $this->mapper->getMapper();

        //reorganize by mid-field
        $this->map_mid = $this->rekeyMapByMidField();

        //$this->data_dict = REDCap::getDataDictionary($origin_pid, 'array', false );

        $this->data_dict = $module->getMetadata($module->getProjectId());
        //$module->emDebug($this->data_dict); exit;

        //data dictionary of origin project
        $this->from_data_dict = $module->getMetadata($origin_id);

    }

    public function rekeyMapByMidField() {
        global $module;
        $map_mid = array();
        foreach ($this->map as $k => $v) {

            $map_mid[$v['mid_field']] = $v;

        }
        return $map_mid;
    }

    public function updateDDFromOriginal() {
        $copy = $this->from_data_dict;
        $this->updateDD($copy);
    }

    public function updateDDFromTarget() {
        $this->updateDD($this->data_dict);
    }

    /**
     * @param $data_dict copy of DD
     * @param bool $orig
     */
    public function updateDD($data_dict, $orig = true) {

        global $module;

        $deleted = array();
        //$module->emDebug($this->map);
        //$module->emDebug($this->map_mid); exit;

        //iterate through the current dictionary rather than starting with the mapper
        foreach ($data_dict as $key => $val) {

            //get the $to_field from both version of the map : the original and the updated mid
            $test_to_field = trim($this->map[$key]['to_field']);
            $test_mid_field = trim($this->map_mid[$key]['to_field']);

            //special case: if the key does not exist in the either map, then assume that it's a valid new addition and let it be
            if (!array_key_exists($key, $this->map) && !array_key_exists($key, $this->map_mid)) {
                $module->emDebug("Found a field in data dictionary that is not in the mapper: ".$key);
                continue;
            }

            //if mid-field exists, use it. Otherwise use $to field
            $to_field =  isset($this->map_mid[$key]['to_field']) ?  trim($this->map_mid[$key]['to_field'])  : trim($this->map[$key]['to_field']);
            $from_form = isset($this->map_mid[$key]['to_field']) ?  trim($this->map_mid[$key]['from_form']) : trim($this->map[$key]['from_form']);
            $to_form  =  isset($this->map_mid[$key]['to_field']) ?  trim($this->map_mid[$key]['to_form'])   : trim($this->map[$key]['to_form']);

            $search = 'illness_sym_comment';
            if(preg_match("/{$search}/i", $key)) {
                $module->emDebug("<br>Checking map $key against original  $test_to_field or updated $test_mid_field");
                $module->emDebug("<br>strcasecmp: ".strcasecmp(trim($key), trim($test_to_field)));
                $module->emDebug("<br>empty: ".!empty(trim($test_mid_field)));
            }

            //TO_FIELD is empty, so delete the field from teh data dictionary
            //if ((empty(trim($to_field))) && (empty(trim($to_mid_field)))) {
            if (empty($to_field)) {

                //TODO: save deleted to an array and display at request
                //$module->emDebug("DELETING $key as $to_field and  $to_mid_field are unset. row: ". $this->map[$key]['original_sequence']);
                $deleted[ $this->map[$key]['original_sequence']] = $to_field;
                unset($data_dict[$key]);
                continue;
            }

            //check the original field name
            //if ((strcasecmp(trim($key), trim($to_field)) !== 0) && (!empty(trim($to_field))) ) {
            if (strcasecmp(trim($key), $to_field) !== 0) {
                //update the fieldname in the data dictionary
                $data_dict[$key]['field_name'] = strtolower($to_field);

                if(preg_match("/{$search}/i", $key)) {
                    $module->emDebug("<br>UUpdating is $key:  to  $to_field");
                }

                //set up the regex pattern for ranching logic replacement
                $this->reg_pattern[] = '/\['.$key.'(\]|\()/';
                $this->reg_replace[]='['.$to_field.'$1';

            }

            //update forms
            if (strcasecmp( trim($from_form), trim($to_form)) !== 0) {
//                echo "<br>$key: comparing  FORMS: ". $from_form.' is not equal to '.$to_form.' in a case insensitive string comparison';
                $data_dict[$key]['form_name'] = strtolower(trim($to_form));
            }
        }

        $module->emDebug("DELETED: n=". count($deleted));

        //Fix the branching logic
        $fixed_data_dict = $this->replaceReferences($data_dict, $this->reg_pattern, $this->reg_replace);
        //$module->emDEbug("NEW DICT", $this->data_dict, $this->data_dict2);

        $this->downloadCSV($fixed_data_dict, "from_original.csv");
    }

    public function constructDataDictRow($map_row) {
        return array($map_row);
    }



    public function updateDDByTargetDataDict() {

        global $module;

        $deleted = array();
        //$module->emDebug($this->map);
        //$module->emDebug($this->map_mid); exit;

        //iterate through the current dictionary rather than starting with the mapper
        foreach ($this->data_dict as $key => $val) {

            //get the $to_field from both version of the map : the original and the updated mid
            $test_to_field = trim($this->map[$key]['to_field']);
            $test_mid_field = trim($this->map_mid[$key]['to_field']);

            //special case: if the key does not exist in the either map, then assume that it's a valid new addition and let it be
            if (!array_key_exists($key, $this->map) && !array_key_exists($key, $this->map_mid)) {
                $module->emDebug("Found a field in data dictionary that is not in the mapper: ".$key);
                continue;
            }

            //if mid-field exists, use it. Otherwise use $to field
            $to_field =  isset($this->map_mid[$key]['to_field']) ?  trim($this->map_mid[$key]['to_field'])  : trim($this->map[$key]['to_field']);
            $from_form = isset($this->map_mid[$key]['to_field']) ?  trim($this->map_mid[$key]['from_form']) : trim($this->map[$key]['from_form']);
            $to_form  =  isset($this->map_mid[$key]['to_field']) ?  trim($this->map_mid[$key]['to_form'])   : trim($this->map[$key]['to_form']);

            $search = 'illness_sym_comment';
            if(preg_match("/{$search}/i", $key)) {
                $module->emDebug("<br>Checking map $key against original  $test_to_field or updated $test_mid_field");
                $module->emDebug("<br>strcasecmp: ".strcasecmp(trim($key), trim($test_to_field)));
                $module->emDebug("<br>empty: ".!empty(trim($test_mid_field)));
            }

            //if ((empty(trim($to_field))) && (empty(trim($to_mid_field)))) {
            if (empty($to_field)) {

                //TODO: save deleted to an array and display at request
                //$module->emDebug("DELETING $key as $to_field and  $to_mid_field are unset. row: ". $this->map[$key]['original_sequence']);
                $deleted[ $this->map[$key]['original_sequence']] = $to_field;
                unset($this->data_dict[$key]);
                continue;
            }


            //check the update mid map, if key matches the to field, leave as is
            //if different and not empty, update the data dictionary

            //check the original field name
            //if ((strcasecmp(trim($key), trim($to_field)) !== 0) && (!empty(trim($to_field))) ) {
            if (strcasecmp(trim($key), $to_field) !== 0) {
                //update the fieldname in the data dictionary
                $this->data_dict[$key]['field_name'] = strtolower($to_field);

                if(preg_match("/{$search}/i", $key)) {
                    $module->emDebug("<br>UUpdating is $key:  to  $to_field");
                }

                //set up the regex pattern for ranching logic replacement
                $this->reg_pattern[] = '/\['.$key.'(\]|\()/';
                $this->reg_replace[]='['.$to_field.'$1';

            }

            //update forms
            if (strcasecmp( trim($from_form), trim($to_form)) !== 0) {
//                echo "<br>$key: comparing  FORMS: ". $from_form.' is not equal to '.$to_form.' in a case insensitive string comparison';
                $this->data_dict[$key]['form_name'] = strtolower(trim($to_form));
            }
        }

        $module->emDebug("DELETED: n=". count($deleted));
        $fixed_data_dict = $this->replaceReferences($this->data_dict, $this->reg_pattern, $this->reg_replace);
        //$module->emDEbug("NEW DICT", $this->data_dict, $this->data_dict2);

        $this->downloadCSV($fixed_data_dict, "from_original.csv");
    }

    /**
     * Abandoned attempt that iterated over the mapping file. Decided to go with starting with the data dictionary
     *
     */
    public function updateDD3() {
        global $module;

        echo "UPDATING!!!";
        //iterate through the mapper
        //compare from and to_field : if different, then update all references to field:
        //  field_name, select_choices_or_calculation, branching_logic
        //compare from and to_form : if different, update all references to field:
        //    form_name

        //$this->mapper->printDictionary(); exit;
        //print "<pre>" . print_r($this->mapper->getMapper(),true). "</pre>";


        foreach ($this->mapper->getMapper() as $key => $map) {
            $replacement =  $map['to_field'];
            //echo "<br> CHECKING: $key vs $replacement";

            if ((strcasecmp(trim($key), trim($replacement)) !== 0) && (!empty(trim($replacement))) ) {
                echo '<br> ' .$key. ' is not equal to '.$replacement.' in a case insensitive string comparison<br>';
                if (array_key_exists($key, $this->data_dict)) {
                    if ($key == 'symp_1') {
//                        print "<br> " . $key . ' VS ' . strtolower(trim($replacement)) . " UPDATING: <pre>" . print_r($this->data_dict[$key], true) . "</pre>";
                    }
                    $this->data_dict[$key]['field_name'] = strtolower(trim($replacement));

                    $this->reg_pattern[] = '/\['.$key.'(\]|\()/';
                    $this->reg_replace[]='['.$replacement.'$1';



                }
            }

            if (strcasecmp( trim($map['from_form']), trim($map['to_form'])) !== 0) {
                echo "<br>$key: comparing  FORMS: ". $map['from_form'].' is not equal to '.$map['to_form'].' in a case insensitive string comparison';
                if (array_key_exists($key, $this->data_dict)) {
                    $this->data_dict[$key]['form_name'] = strtolower(trim($map['to_form']));
                    //$module->emDebug($map['to_form']);
                }
            }
        }

        //Fix the branching logic
        $fixed_data_dict = $this->replaceReferences($this->data_dict, $this->reg_pattern, $this->reg_replace);
        //$module->emDEbug("NEW DICT", $this->data_dict, $this->data_dict2);

        $this->downloadCSV($fixed_data_dict, "from_original.csv");

    }

    /**
     * Fix the branching logic
     *
     * @param $data_dict
     * @param $target
     * @param $replacement
     */
    private function replaceReferences($data_dict, $target, $replacement) {
        global $module;

        //$module->emDebug($target, $replacement);

        $foo =  preg_replace($target, $replacement, json_encode($data_dict));
        return (json_decode($foo, true));


        /**
        foreach ($this->data_dict as $field => $dict_row) {
            //replace in calculated field
            $re = '/\['.$target.'(\]|\()/m';
            $re2 = '/.*?(?<target>\['.$target.'\]|\().*?/m';
            $str = '[yboc1] + [yboc2] + [yboc3] + [yboc4] + [yboc5]';


            echo "<br><br>";
            echo "<br>REPLACED: " . preg_replace($re, $replacement, $this->data_dict[$target]['select_choices_or_calculations']);

            echo "<br>REPLACED: " . preg_replace($re, $replacement, $this->data_dict[$target]['branching_logic']);

        }
*/
    }


    private function downloadCSV($data_dict, $filename='temp.csv')
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $fp = fopen('php://output', 'wb');

        foreach ($data_dict as $row) {
            fputcsv($fp, $row);//, "\t", '"' );
        }

        fclose($fp);
    }


    public function print($num=5) {
        global $module;

        $i=0;
        foreach ($this->data_dict as $foo => $bar) {
            //$module->emDebug($foo. " : " .$bar['field_name'] . " / form ".$bar['form_name'] );
            //echo $i . " :: " . $foo. " : " .$bar['field_name'] . " / form ".$bar['form_name'] . "<br>";
            //echo $i . " : ".  $bar;
            if ($i > $num) break;
            $i++;
        }
    }
}
