<?php

namespace DE\RUB\FieldShuffleExternalModule;

/**
 * Pure parser, validator, permutation, and metadata transformation logic for
 * the server-side paged shuffle modes.
 */
class PagedShuffle
{
    const MODE_PAGED = "paged";
    const MODE_SINGLE = "paged-single";
    const AT_SECTION_HEADER = "@SHUFFLE-FIELDS-SH";
    const BLANK_SECTION_HEADER = "&nbsp;";

    /**
     * Parses and validates a paged shuffle expression against one instrument.
     *
     * @param string $mode One of the MODE_* constants.
     * @param string $target Storage field carrying the action tag.
     * @param string $expression Unquoted action-tag parameter.
     * @param array<string, mixed> $form_fields Ordered instrument fields.
     * @param array<string, array<string, mixed>> $metadata Project metadata.
     * @return array<string, mixed>
     */
    public static function buildConfiguration($mode, $target, $expression, $form_fields, $metadata)
    {
        if ($mode !== self::MODE_PAGED && $mode !== self::MODE_SINGLE) {
            throw new \InvalidArgumentException("Unknown paged shuffle mode.");
        }
        if (!array_key_exists($target, $form_fields) || !isset($metadata[$target])) {
            throw new \InvalidArgumentException("Order-storage field '{$target}' does not exist on this instrument.");
        }
        if (($metadata[$target]["element_type"] ?? null) !== "text"
            || !empty($metadata[$target]["element_validation_type"])) {
            throw new \InvalidArgumentException(
                "Order-storage field '{$target}' must be an unvalidated Text Box field."
            );
        }

        $units = self::parseExpression($expression, $mode === self::MODE_SINGLE);
        $field_order = array_keys($form_fields);
        $positions = array_flip($field_order);
        $seen = array();

        foreach ($units as $unit_index => &$unit) {
            $unit["id"] = $unit_index;
            $unit_positions = array();
            foreach ($unit["fields"] as $field) {
                if (!array_key_exists($field, $positions) || !isset($metadata[$field])) {
                    throw new \InvalidArgumentException("Field '{$field}' does not exist on this instrument.");
                }
                if ($field === $target) {
                    throw new \InvalidArgumentException("The order-storage field '{$target}' cannot shuffle itself.");
                }
                if (isset($seen[$field])) {
                    throw new \InvalidArgumentException("Field '{$field}' occurs more than once in the configuration.");
                }
                $seen[$field] = true;
                $unit_positions[] = $positions[$field];
            }

            sort($unit_positions, SORT_NUMERIC);
            $unit["start"] = $unit_positions[0];
            $unit["end"] = $unit_positions[count($unit_positions) - 1];
            if ($unit["grouped"] && $unit_positions !== range($unit["start"], $unit["end"])) {
                throw new \InvalidArgumentException(
                    "Grouped fields must occupy one contiguous block in the instrument metadata."
                );
            }
        }
        unset($unit);

        usort($units, function ($left, $right) {
            return $left["start"] <=> $right["start"];
        });

        // Give unit IDs a stable, metadata-order meaning after sorting.
        foreach ($units as $unit_index => &$unit) {
            $unit["id"] = $unit_index;
        }
        unset($unit);

        $regions = array();
        if ($mode === self::MODE_PAGED) {
            $regions[] = array_map(function ($unit) {
                return $unit["id"];
            }, $units);
        } else {
            foreach ($units as $unit) {
                $last_region_index = count($regions) - 1;
                if ($last_region_index < 0) {
                    $regions[] = array($unit["id"]);
                    continue;
                }
                $previous_id = $regions[$last_region_index][count($regions[$last_region_index]) - 1];
                $previous = $units[$previous_id];
                if ($unit["start"] === $previous["end"] + 1) {
                    $regions[$last_region_index][] = $unit["id"];
                } else {
                    $regions[] = array($unit["id"]);
                }
            }
        }

        $warnings = array();
        if ($mode === self::MODE_SINGLE) {
            foreach ($units as &$unit) {
                $header = null;
                $header_fields = array();
                $natural_header = null;
                $fields_in_metadata_order = $unit["fields"];
                usort($fields_in_metadata_order, function ($left, $right) use ($positions) {
                    return $positions[$left] <=> $positions[$right];
                });

                foreach ($fields_in_metadata_order as $field) {
                    $override = self::findSectionHeaderOverride($metadata[$field]["misc"] ?? "");
                    if ($override !== null) {
                        $header_fields[] = $field;
                        if ($header === null) {
                            $header = $override;
                        }
                    }
                    $candidate = $metadata[$field]["element_preceding_header"] ?? "";
                    if ($natural_header === null && $candidate !== null && $candidate !== "") {
                        $natural_header = $candidate;
                    }
                }

                if (count($header_fields) > 1) {
                    $warnings[] = "Unit containing '" . implode(", ", $unit["fields"])
                        . "' has multiple " . self::AT_SECTION_HEADER . " tags; using the one on '"
                        . $header_fields[0] . "'.";
                }
                if ($header === null) {
                    $header = $natural_header === null ? self::BLANK_SECTION_HEADER : $natural_header;
                }
                if ($header === "") {
                    $header = self::BLANK_SECTION_HEADER;
                }
                $unit["header"] = $header;
            }
            unset($unit);
        }

        return array(
            "mode" => $mode,
            "target" => $target,
            "omit_target" => $mode === self::MODE_SINGLE
                && self::hasActionTag($metadata[$target]["misc"] ?? "", array("@HIDDEN", "@HIDDEN-SURVEY")),
            "expression" => $expression,
            "units" => $units,
            "regions" => $regions,
            "field_order" => $field_order,
            "positions" => $positions,
            "fields" => array_keys($seen),
            "warnings" => $warnings,
        );
    }

    /**
     * Generates a new realized order. PAGED shuffles globally; PAGED-SINGLE
     * shuffles independently inside each contiguous region so fixed fields stay
     * fixed and differently-sized units never cross a fixed-field boundary.
     *
     * @param array<string, mixed> $configuration
     * @return array<int, array<string, mixed>>
     */
    public static function generateOrder($configuration)
    {
        $realized = array();
        foreach ($configuration["regions"] as $region) {
            $unit_ids = self::shuffledCopy($region);
            foreach ($unit_ids as $unit_id) {
                $unit = $configuration["units"][$unit_id];
                $realized_parts = array();
                foreach ($unit["parts"] as $part) {
                    $realized_parts[] = count($part) > 1 ? self::shuffledCopy($part) : $part;
                }
                $realized[] = array(
                    "unit_id" => $unit_id,
                    "parts" => $realized_parts,
                    "fields" => self::flatten($realized_parts),
                    "grouped" => $unit["grouped"],
                );
            }
        }
        return $realized;
    }

    /**
     * Strictly validates and restores a persisted order.
     *
     * @param array<string, mixed> $configuration
     * @param string $stored
     * @return array<int, array<string, mixed>>
     */
    public static function restoreOrder($configuration, $stored)
    {
        $stored = trim((string) $stored);
        if ($stored === "") {
            throw new \InvalidArgumentException("The stored order is empty.");
        }

        $tokens = explode("-", $stored);
        if (count($tokens) !== count($configuration["units"])) {
            throw new \InvalidArgumentException("The stored order has an incompatible number of shuffle units.");
        }

        $realized = array();
        $used_units = array();
        foreach ($tokens as $token) {
            if ($token === "") {
                throw new \InvalidArgumentException("The stored order contains an empty shuffle unit.");
            }
            $stored_parts = array();
            foreach (explode("+", $token) as $part) {
                $fields = explode("~", $part);
                if (in_array("", $fields, true)) {
                    throw new \InvalidArgumentException("The stored order contains an empty field name.");
                }
                $stored_parts[] = $fields;
            }
            $stored_fields = self::flatten($stored_parts);
            if (count($stored_fields) !== count(array_unique($stored_fields))) {
                throw new \InvalidArgumentException("The stored order contains a duplicate field.");
            }

            $matching_id = null;
            foreach ($configuration["units"] as $unit) {
                if (self::sameMembers($stored_fields, $unit["fields"])) {
                    $matching_id = $unit["id"];
                    break;
                }
            }
            if ($matching_id === null || isset($used_units[$matching_id])) {
                throw new \InvalidArgumentException("The stored order is incompatible with the configured units.");
            }

            $unit = $configuration["units"][$matching_id];
            if (!$unit["grouped"]) {
                if (count($stored_parts) !== 1 || count($stored_parts[0]) !== 1) {
                    throw new \InvalidArgumentException("A singleton unit has invalid stored grouping.");
                }
            } else {
                if (count($stored_parts) !== count($unit["parts"])) {
                    throw new \InvalidArgumentException("A grouped unit has incompatible stored subsequences.");
                }
                foreach ($unit["parts"] as $part_index => $configured_part) {
                    $stored_part = $stored_parts[$part_index];
                    if (!self::sameMembers($stored_part, $configured_part)) {
                        throw new \InvalidArgumentException("A grouped unit has incompatible stored subsequences.");
                    }
                    if (count($configured_part) === 1 && $stored_part !== $configured_part) {
                        throw new \InvalidArgumentException("A fixed field changed position inside a grouped unit.");
                    }
                }
            }

            $used_units[$matching_id] = true;
            $realized[] = array(
                "unit_id" => $matching_id,
                "parts" => $stored_parts,
                "fields" => $stored_fields,
                "grouped" => $unit["grouped"],
            );
        }

        if (count($used_units) !== count($configuration["units"])) {
            throw new \InvalidArgumentException("The stored order does not contain every configured unit exactly once.");
        }

        $offset = 0;
        foreach ($configuration["regions"] as $region) {
            $actual = array();
            for ($i = 0; $i < count($region); $i++) {
                $actual[] = $realized[$offset + $i]["unit_id"];
            }
            if (!self::sameMembers($actual, $region)) {
                throw new \InvalidArgumentException("The stored order moves a unit across a fixed-field region boundary.");
            }
            $offset += count($region);
        }

        return $realized;
    }

    /**
     * Serializes the exact realized outer and internal order.
     *
     * @param array<int, array<string, mixed>> $realized
     * @return string
     */
    public static function serializeOrder($realized)
    {
        $units = array();
        foreach ($realized as $unit) {
            if (!$unit["grouped"]) {
                $units[] = $unit["fields"][0];
                continue;
            }
            $parts = array();
            foreach ($unit["parts"] as $part) {
                $parts[] = implode("~", $part);
            }
            $units[] = implode("+", $parts);
        }
        return implode("-", $units);
    }

    /**
     * Applies a realized order to copies of the Project form/metadata arrays.
     *
     * @param array<string, mixed> $configuration
     * @param array<int, array<string, mixed>> $realized
     * @param array<string, mixed> $form_fields
     * @param array<string, array<string, mixed>> $metadata
     * @return array{form_fields: array<string, mixed>, metadata: array<string, array<string, mixed>>}
     */
    public static function transform($configuration, $realized, $form_fields, $metadata)
    {
        $original_order = array_keys($form_fields);
        $new_order = $original_order;
        $slot_orders = array();
        foreach ($original_order as $position => $field) {
            $slot_orders[$position] = $metadata[$field]["field_order"] ?? null;
        }

        if ($configuration["mode"] === self::MODE_PAGED) {
            $positions = array();
            foreach ($configuration["fields"] as $field) {
                $positions[] = $configuration["positions"][$field];
            }
            sort($positions, SORT_NUMERIC);
            $realized_fields = array();
            foreach ($realized as $unit) {
                $realized_fields[] = $unit["fields"][0];
            }

            $slot_headers = array();
            foreach ($positions as $position) {
                $slot_field = $original_order[$position];
                $slot_headers[] = $metadata[$slot_field]["element_preceding_header"] ?? null;
            }
            foreach ($configuration["fields"] as $field) {
                $metadata[$field]["element_preceding_header"] = null;
            }
            foreach ($positions as $index => $position) {
                $field = $realized_fields[$index];
                $new_order[$position] = $field;
                $metadata[$field]["element_preceding_header"] = $slot_headers[$index];
                if ($slot_orders[$position] !== null) {
                    $metadata[$field]["field_order"] = $slot_orders[$position];
                }
            }
        } else {
            foreach ($configuration["fields"] as $field) {
                $metadata[$field]["element_preceding_header"] = null;
            }

            $offset = 0;
            foreach ($configuration["regions"] as $region) {
                $first_unit = $configuration["units"][$region[0]];
                $last_unit = $configuration["units"][$region[count($region) - 1]];
                $position = $first_unit["start"];

                for ($i = 0; $i < count($region); $i++) {
                    $realized_unit = $realized[$offset + $i];
                    $configured_unit = $configuration["units"][$realized_unit["unit_id"]];
                    foreach ($realized_unit["fields"] as $field_index => $field) {
                        $new_order[$position] = $field;
                        if ($slot_orders[$position] !== null) {
                            $metadata[$field]["field_order"] = $slot_orders[$position];
                        }
                        if ($field_index === 0) {
                            $metadata[$field]["element_preceding_header"] = $configured_unit["header"];
                        }
                        $position++;
                    }
                }
                $offset += count($region);

                // End the last unit's page before any fixed fields that follow.
                $next_position = $last_unit["end"] + 1;
                if (isset($original_order[$next_position])) {
                    $next_field = $original_order[$next_position];
                    if (!in_array($next_field, $configuration["fields"], true)
                        && empty($metadata[$next_field]["element_preceding_header"])) {
                        $metadata[$next_field]["element_preceding_header"] = self::BLANK_SECTION_HEADER;
                    }
                }
            }
        }

        $new_form_fields = array();
        foreach ($new_order as $field) {
            if (!empty($configuration["omit_target"]) && $field === $configuration["target"]) {
                continue;
            }
            $new_form_fields[$field] = $form_fields[$field];
        }

        return array(
            "form_fields" => $new_form_fields,
            "metadata" => $metadata,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private static function parseExpression($expression, $allow_groups)
    {
        $expression = trim((string) $expression);
        if ($expression === "") {
            throw new \InvalidArgumentException("The action-tag parameter is empty.");
        }

        $tokens = array();
        $token = "";
        $depth = 0;
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $character = $expression[$i];
            if ($character === "(") {
                $depth++;
                if ($depth > 1) {
                    throw new \InvalidArgumentException("Nested groups are not supported.");
                }
            } elseif ($character === ")") {
                $depth--;
                if ($depth < 0) {
                    throw new \InvalidArgumentException("The action-tag parameter has unmatched parentheses.");
                }
            }

            if ($character === "," && $depth === 0) {
                $tokens[] = trim($token);
                $token = "";
            } else {
                $token .= $character;
            }
        }
        if ($depth !== 0) {
            throw new \InvalidArgumentException("The action-tag parameter has unmatched parentheses.");
        }
        $tokens[] = trim($token);

        $units = array();
        foreach ($tokens as $token) {
            if ($token === "") {
                throw new \InvalidArgumentException("The action-tag parameter contains an empty shuffle unit.");
            }
            $grouped = $token[0] === "(" || substr($token, -1) === ")";
            if ($grouped) {
                if (!$allow_groups) {
                    throw new \InvalidArgumentException("Groups are not supported by @SHUFFLE-FIELDS-PAGED.");
                }
                if ($token[0] !== "(" || substr($token, -1) !== ")") {
                    throw new \InvalidArgumentException("The action-tag parameter has malformed parentheses.");
                }
                $inside = trim(substr($token, 1, -1));
                if ($inside === "") {
                    throw new \InvalidArgumentException("Empty groups are not supported.");
                }
                $parts = array();
                foreach (explode(",", $inside) as $part_text) {
                    $part_text = trim($part_text);
                    if ($part_text === "") {
                        throw new \InvalidArgumentException("A grouped unit contains an empty subsequence.");
                    }
                    $part = array();
                    foreach (explode("~", $part_text) as $field) {
                        $field = trim($field);
                        self::validateFieldName($field);
                        $part[] = $field;
                    }
                    $parts[] = $part;
                }
            } else {
                if (strpos($token, "~") !== false) {
                    throw new \InvalidArgumentException("The ~ operator is valid only inside a parenthesized unit.");
                }
                self::validateFieldName($token);
                $parts = array(array($token));
            }

            $units[] = array(
                "grouped" => $grouped,
                "parts" => $parts,
                "fields" => self::flatten($parts),
            );
        }
        return $units;
    }

    private static function validateFieldName($field)
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
            throw new \InvalidArgumentException("Invalid REDCap field name '{$field}'.");
        }
    }

    private static function findSectionHeaderOverride($misc)
    {
        if (!is_string($misc) || strpos($misc, self::AT_SECTION_HEADER) === false) {
            return null;
        }
        $parsed = ActionTagParser::parse($misc);
        foreach ($parsed["parts"] as $part) {
            if (($part["type"] ?? null) !== "tag" || ($part["text"] ?? null) !== self::AT_SECTION_HEADER) {
                continue;
            }
            if (!is_array($part["param"] ?? null) || ($part["param"]["type"] ?? null) !== "quoted-string") {
                continue;
            }
            return self::unquote($part["param"]["text"] ?? "");
        }
        return null;
    }

    private static function hasActionTag($misc, $tag_names)
    {
        if (!is_string($misc) || strpos($misc, "@") === false) {
            return false;
        }
        foreach (ActionTagParser::parse($misc)["parts"] as $part) {
            if (($part["type"] ?? null) === "tag" && in_array($part["text"] ?? "", $tag_names, true)) {
                return true;
            }
        }
        return false;
    }

    private static function unquote($text)
    {
        $text = (string) $text;
        if (strlen($text) < 2) {
            return $text;
        }
        $quote = $text[0];
        if (($quote !== '"' && $quote !== "'") || substr($text, -1) !== $quote) {
            return $text;
        }
        $text = substr($text, 1, -1);
        return str_replace(array("\\" . $quote, "\\\\"), array($quote, "\\"), $text);
    }

    private static function shuffledCopy($values)
    {
        $copy = array_values($values);
        for ($i = count($copy) - 1; $i > 0; $i--) {
            $swap = random_int(0, $i);
            $value = $copy[$i];
            $copy[$i] = $copy[$swap];
            $copy[$swap] = $value;
        }
        return $copy;
    }

    private static function flatten($parts)
    {
        $flat = array();
        foreach ($parts as $part) {
            foreach ($part as $field) {
                $flat[] = $field;
            }
        }
        return $flat;
    }

    private static function sameMembers($left, $right)
    {
        $left = array_values($left);
        $right = array_values($right);
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);
        return $left === $right;
    }
}
