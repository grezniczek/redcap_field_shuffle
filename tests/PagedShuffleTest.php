<?php

use DE\RUB\FieldShuffleExternalModule\PagedShuffle;

require_once dirname(__DIR__) . "/classes/ActionTagParser.php";
require_once dirname(__DIR__) . "/classes/PagedShuffle.php";

function failTest($message)
{
    throw new RuntimeException($message);
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        failTest($message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        failTest($message);
    }
}

function expectInvalid($callback, $message)
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    failTest($message);
}

function instrument($fields, $headers = array(), $misc = array())
{
    $form_fields = array();
    $metadata = array();
    foreach (array_values($fields) as $index => $field) {
        $form_fields[$field] = array("field_order" => $index + 1);
        $metadata[$field] = array(
            "field_order" => $index + 1,
            "element_type" => "text",
            "element_validation_type" => null,
            "element_preceding_header" => $headers[$field] ?? null,
            "misc" => $misc[$field] ?? null,
        );
    }
    return array($form_fields, $metadata);
}

function testGrammarAndRoundTrip()
{
    list($fields, $metadata) = instrument(array("order", "q1", "q2", "q3", "q4", "q5", "q6"));
    $configuration = PagedShuffle::buildConfiguration(
        PagedShuffle::MODE_SINGLE,
        "order",
        "q1,(q2~q3,q4~q5),q6",
        $fields,
        $metadata
    );

    assertSameValue(array(array("q1"), array("q2", "q3", "q4", "q5"), array("q6")), array_map(
        function ($unit) {
            return $unit["fields"];
        },
        $configuration["units"]
    ), "Top-level units were parsed incorrectly.");
    assertSameValue(
        array(array("q2", "q3"), array("q4", "q5")),
        $configuration["units"][1]["parts"],
        "The ~ precedence inside the grouped unit was parsed incorrectly."
    );

    $stored = "q6-q3~q2+q5~q4-q1";
    $restored = PagedShuffle::restoreOrder($configuration, $stored);
    assertSameValue($stored, PagedShuffle::serializeOrder($restored), "Stored order did not round-trip.");

    for ($i = 0; $i < 30; $i++) {
        $generated = PagedShuffle::generateOrder($configuration);
        $serialized = PagedShuffle::serializeOrder($generated);
        $replayed = PagedShuffle::restoreOrder($configuration, $serialized);
        assertSameValue($serialized, PagedShuffle::serializeOrder($replayed), "Generated order did not replay exactly.");
    }
}

function testInvalidGrammarAndStoredOrders()
{
    list($fields, $metadata) = instrument(array("order", "q1", "q2", "q3", "q4"));
    $buildSingle = function ($expression) use ($fields, $metadata) {
        return PagedShuffle::buildConfiguration(
            PagedShuffle::MODE_SINGLE,
            "order",
            $expression,
            $fields,
            $metadata
        );
    };

    foreach (array("", "q1,,q2", "q1,(q2,q3", "q1,q2~q3", "q1,(q2~~q3)", "q1,q1") as $invalid) {
        expectInvalid(function () use ($buildSingle, $invalid) {
            $buildSingle($invalid);
        }, "Invalid grammar was accepted: {$invalid}");
    }
    expectInvalid(function () use ($fields, $metadata) {
        PagedShuffle::buildConfiguration(PagedShuffle::MODE_PAGED, "order", "q1,(q2,q3)", $fields, $metadata);
    }, "PAGED accepted grouped syntax.");
    $invalid_target_metadata = $metadata;
    $invalid_target_metadata["order"]["element_type"] = "descriptive";
    expectInvalid(function () use ($fields, $invalid_target_metadata) {
        PagedShuffle::buildConfiguration(
            PagedShuffle::MODE_PAGED,
            "order",
            "q1,q2",
            $fields,
            $invalid_target_metadata
        );
    }, "A non-Text storage field was accepted.");

    $configuration = $buildSingle("q1,(q2~q3),q4");
    foreach (array(
        "q1-q2~q3",
        "q1-q2~q2-q4",
        "q1-q2+q3-q4",
        "q1-q3~q4-q2",
    ) as $invalid) {
        expectInvalid(function () use ($configuration, $invalid) {
            PagedShuffle::restoreOrder($configuration, $invalid);
        }, "Invalid stored order was accepted: {$invalid}");
    }
}

function testPagedFixedSlots()
{
    list($fields, $metadata) = instrument(
        array("order", "a", "fixed", "b", "c", "form_complete"),
        array("a" => "Page A", "b" => "Page B")
    );
    $configuration = PagedShuffle::buildConfiguration(
        PagedShuffle::MODE_PAGED,
        "order",
        "a,b,c",
        $fields,
        $metadata
    );
    $realized = PagedShuffle::restoreOrder($configuration, "c-a-b");
    $result = PagedShuffle::transform($configuration, $realized, $fields, $metadata);

    assertSameValue(
        array("order", "c", "fixed", "a", "b", "form_complete"),
        array_keys($result["form_fields"]),
        "PAGED did not substitute selected fields into the selected slots."
    );
    assertSameValue("Page A", $result["metadata"]["c"]["element_preceding_header"], "First slot header moved.");
    assertSameValue("Page B", $result["metadata"]["a"]["element_preceding_header"], "Second slot header moved.");
    assertSameValue(null, $result["metadata"]["b"]["element_preceding_header"], "Third slot header changed.");
    assertSameValue(2, $result["metadata"]["c"]["field_order"], "Field order was not reassigned to its slot.");
}

function testPagedSingleRegionsAndHeaders()
{
    list($fields, $metadata) = instrument(
        array("order", "q1", "q2", "q3", "fixed", "q4", "q5", "form_complete"),
        array("q1" => "Q1 header", "q2" => "Natural group header")
    );
    $configuration = PagedShuffle::buildConfiguration(
        PagedShuffle::MODE_SINGLE,
        "order",
        "q1,(q2~q3),q4,q5",
        $fields,
        $metadata
    );
    assertSameValue(array(array(0, 1), array(2, 3)), $configuration["regions"], "Regions were not detected.");

    $stored = "q3~q2-q1-q5-q4";
    $realized = PagedShuffle::restoreOrder($configuration, $stored);
    $result = PagedShuffle::transform($configuration, $realized, $fields, $metadata);
    assertSameValue(
        array("order", "q3", "q2", "q1", "fixed", "q5", "q4", "form_complete"),
        array_keys($result["form_fields"]),
        "PAGED-SINGLE did not reorder units inside each region."
    );
    assertSameValue(
        "Natural group header",
        $result["metadata"]["q3"]["element_preceding_header"],
        "The first natural unit header was not attached to the first realized field."
    );
    assertSameValue(null, $result["metadata"]["q2"]["element_preceding_header"], "An internal header was not cleared.");
    assertSameValue("Q1 header", $result["metadata"]["q1"]["element_preceding_header"], "Singleton header changed.");
    assertSameValue(
        PagedShuffle::BLANK_SECTION_HEADER,
        $result["metadata"]["fixed"]["element_preceding_header"],
        "The first fixed field after a region did not close the final unit page."
    );
    assertSameValue(
        PagedShuffle::BLANK_SECTION_HEADER,
        $result["metadata"]["q5"]["element_preceding_header"],
        "A headerless unit did not receive a page break."
    );

    expectInvalid(function () use ($configuration) {
        PagedShuffle::restoreOrder($configuration, "q4-q1-q3~q2-q5");
    }, "A stored order crossed a fixed-field region boundary.");
}

function testSectionHeaderOverridePrecedence()
{
    list($fields, $metadata) = instrument(
        array("order", "q1", "q2", "q3"),
        array("q1" => "Natural"),
        array(
            "q1" => '@SHUFFLE-FIELDS-SH="Override one"',
            "q2" => '@SHUFFLE-FIELDS-SH="Override two"',
        )
    );
    $configuration = PagedShuffle::buildConfiguration(
        PagedShuffle::MODE_SINGLE,
        "order",
        "(q1~q2),q3",
        $fields,
        $metadata
    );
    assertSameValue("Override one", $configuration["units"][0]["header"], "Section-header override lost precedence.");
    assertSameValue(1, count($configuration["warnings"]), "Duplicate section-header overrides did not warn.");
}

function testHiddenSingleStorageFieldIsNotAPage()
{
    list($fields, $metadata) = instrument(
        array("order", "q1", "q2"),
        array(),
        array("order" => "@SHUFFLE-FIELDS-PAGED-SINGLE=\"q1,q2\" @HIDDEN-SURVEY")
    );
    $configuration = PagedShuffle::buildConfiguration(
        PagedShuffle::MODE_SINGLE,
        "order",
        "q1,q2",
        $fields,
        $metadata
    );
    $realized = PagedShuffle::restoreOrder($configuration, "q2-q1");
    $result = PagedShuffle::transform($configuration, $realized, $fields, $metadata);
    assertTrueValue($configuration["omit_target"], "The hidden storage field was not marked for omission.");
    assertSameValue(
        array("q2", "q1"),
        array_keys($result["form_fields"]),
        "The hidden storage field would create a blank PAGED-SINGLE page."
    );
}

$tests = array(
    "grammar and round trip" => "testGrammarAndRoundTrip",
    "invalid grammar and stored orders" => "testInvalidGrammarAndStoredOrders",
    "PAGED fixed slots" => "testPagedFixedSlots",
    "PAGED-SINGLE regions and headers" => "testPagedSingleRegionsAndHeaders",
    "section-header precedence" => "testSectionHeaderOverridePrecedence",
    "hidden PAGED-SINGLE storage field" => "testHiddenSingleStorageFieldIsNotAPage",
);

foreach ($tests as $label => $test) {
    $test();
    echo "PASS: {$label}\n";
}

echo "All paged shuffle tests passed.\n";
