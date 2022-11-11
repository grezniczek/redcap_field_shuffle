<?php namespace RUB\REDCapScrambleExternalModule;

require_once "classes/InjectionHelper.php";
require_once "classes/ActionTagParser.php";

/**
 * ExternalModule class for REDCap Scramble.
 */
class REDCapScrambleExternalModule extends \ExternalModules\AbstractExternalModule {


    const AT_SCRAMBLE = "@SCRAMBLE";

    #region Constructor and Instance Variables

    /**
     * @var InjectionHelper
     */
    public $ih = null;

    /**
     * EM Framework (tooling support)
     * @var \ExternalModules\Framework
     */
    private $fw;
 
    function __construct() {
        parent::__construct();
        $this->fw = $this->framework;
        $this->ih = InjectionHelper::init($this);
    }

    #endregion

    #region Hooks

    function redcap_data_entry_form($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {

        $this->ih->js("js/redcap_scramble.js", true);

    }

    function redcap_survey_page($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1) {

        $settings = $this->get_scramble_settings($project_id, $instrument, $event_id, $repeat_instance);

        $this->ih->js("js/redcap_scramble.js", true);
        print "<script>REDCap.EM.RUB.REDCapScramble.init(".json_encode($settings, JSON_UNESCAPED_UNICODE).");</script>";
    }

    #endregion


    private function get_scramble_settings($pid, $form, $event_id, $instance) {
        $tags = [];
        $Proj = new \Project($pid);

        foreach ($Proj->forms[$form]["fields"] as $field => $_) {
            $meta = $Proj->metadata[$field];
            $misc = $meta["misc"] ?? "";
            if (strpos($misc, self::AT_SCRAMBLE) !== false) {
                $result = ActionTagParser::parse($misc);
                foreach ($result["parts"] as $at) {
                    if ($at["text"] == self::AT_SCRAMBLE && $at["param"]["type"] == "quoted-string") {
                        $tags[$field] = array_map(function($s) { return trim($s); }, explode(",",trim($at["param"]["text"],"\"")));
                    }
                }
            }
        }
        foreach ($tags as $field => &$fields) {
            // Generate random order
            $sort_by = [];
            while (count($sort_by) < count($fields)) {
                $sort_by[] = random_int(PHP_INT_MIN, PHP_INT_MAX);
            }
            array_multisort($sort_by, SORT_NUMERIC, $fields);
        }
        return array(
            "fields" => $tags
        );
    }

}