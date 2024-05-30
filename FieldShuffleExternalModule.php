<?php namespace RUB\FieldShuffleExternalModule;

require_once "classes/InjectionHelper.php";
require_once "classes/ActionTagParser.php";

/**
 * ExternalModule class for Field Shuffle.
 */
class FieldShuffleExternalModule extends \ExternalModules\AbstractExternalModule {

    const AT_SHUFFLE_SURVEY = "@SHUFFLE-FIELDS-SURVEY";
    const AT_SHUFFLE_DATAENTRY = "@SHUFFLE-FIELDS-DATAENTRY";

    #region Hooks

    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $settings = $this->get_settings($project_id, $instrument, self::AT_SHUFFLE_DATAENTRY);
        if (count($settings["targets"])) {
            $settings["isSurvey"] = false;
            $ih = InjectionHelper::init($this);
            $ih->js("js/field-shuffle-em.js", true);
            print "<script>REDCap.EM.RUB.FieldShuffle.init(".json_encode($settings, JSON_UNESCAPED_UNICODE).");</script>";
        }
    }

    function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        $settings = $this->get_settings($project_id, $instrument, self::AT_SHUFFLE_SURVEY);
        if (count($settings["targets"])) {
            $settings["isSurvey"] = true;
            $ih = InjectionHelper::init($this);
            $ih->js("js/field-shuffle-em.js", true);
            print "<script>REDCap.EM.RUB.FieldShuffle.init(".json_encode($settings, JSON_UNESCAPED_UNICODE).");</script>";
        }
    }

    #endregion


    private function get_settings($pid, $form, $at_name) {
        $targets = [];
        $Proj = new \Project($pid);
        foreach ($Proj->forms[$form]["fields"] as $target => $_) {
            $meta = $Proj->metadata[$target] ?? [];
            $misc = $meta["misc"] ?? "";
            if (strpos($misc, $at_name) !== false) {
                $result = ActionTagParser::parse($misc);
                foreach ($result["parts"] as $at) {
                    if ($at["text"] == $at_name && $at["param"]["type"] == "quoted-string") {
                        $targets[$target]["original"] = $this->parse_params($at["param"]["text"]);
                    }
                }
            }
        }
        foreach ($targets as $target => $target_data) {
            // Generate random order
            $sort_by = [];
            while (count($sort_by) < count($target_data["original"])) {
                $sort_by[] = random_int(PHP_INT_MIN, PHP_INT_MAX);
            }
            $sorted = array_merge($target_data["original"]);
            array_multisort($sort_by, SORT_NUMERIC, $sorted);
            $targets[$target]["shuffled"] = $sorted;
            // To make the mapping, we need to consider that the order given in 
            // the AT parameter does not reflect the order of the fields in the form.
            // Therefore, let's get the order of the fields in the form
            $original_flat = array_merge(...$targets[$target]["original"]);
            $ordered_fields = [];
            foreach ($Proj->forms[$form]["fields"] as $field => $_) {
                $field = $this->framework->escape($field);
                if (in_array($field, $original_flat)) {
                    $ordered_fields[$Proj->metadata[$field]["field_order"]] = $field;
                }
            }
            sort($ordered_fields);
            $ordered_fields = array_values($ordered_fields);
            $shuffled_flat = array_merge(...$targets[$target]["shuffled"]);
            // Now map based on actual order
            for ($i = 0; $i < count($ordered_fields); $i++) {
                $targets[$target]["map"][$ordered_fields[$i]] = $shuffled_flat[$i];
            }
            $targets[$target]["original_flat"] = $original_flat;
            $targets[$target]["actual_flat"] = $ordered_fields;
            $targets[$target]["length"] = count($original_flat);
        }
        return array(
            "debug" => $this->getProjectSetting("debug") == true,
            "targets" => $targets,
        );
    }


    private function parse_params($params) {
        $order = [];
        $pattern = '/(?|([a-z][a-z0-9_]*)|\(([^()]+)\))/';
        preg_match_all($pattern, $params, $matches);
        for ($i = 0; $i < count($matches[0]); $i++) {
            if (!empty($matches[1][$i])) {
                $order[] = array_map(function($s) { return trim($s); }, explode(",",trim($matches[1][$i],"\"")));
            } 
        }
        return $order;
    }
}