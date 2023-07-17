# Field Shuffle

A REDCap External Module that puts fields into a random order

## Installation

- Install this module from the REDCap External Module Repository and enable it.

Manual installation:

- Clone this repo into `<redcap-root>/modules/redcap_field_shuffle_v<version-number>`.
- Go to _Control Center > External Modules > Manage_ and enable 'Field Shuffle'.

## Configuration

A **debug** mode can be enable in the module's project settings. When enabled, some information about the module's actions that may be useful for troubleshooting is output to the browser console.

## Usage

The module's actions are controlled by **Action Tags**: 

- **`@SHUFFLE-FIELDS-SURVEY`** will randomize the question order on survey pages. Please note that all fields that are shuffled as well as the field that holds the displayed order **must** be on the same survey page.

- **`@SHUFFLE-FIELDS-DATAENTRY`** will randomize the question order on data entry pages. This may be useful to see the questions in the same order as viewed by a survey participant. In this case, make sure that both action tags are applied to the same field (holding the order) with the exact same parameters.

Both action tags take should be applied to the field that should hold the question order. It must be a field of type _Text Box_ without any validation. It is recommended to apply the `@HIDDEN-SURVEY` and the `@READONLY` action tags to this field as well.

The `@SHUFFLE-FIELDS-SURVEY` and `@SHUFFLE-FIELDS-DATAENTRY` both take a comma-separated list (in quotes) of the variable names of the fields the order of which should be randomized.

For example, let's assume a survey with four questions, _q1_, _q2_, _q3_, and _q4_. To randomize them, add  
> `@SHUFFLE-FIELDS-SURVEY="q1,q2,q3,q4"`  
> `@HIDDEN-SURVEY @READONLY`

to a further field, e.g., _displayed_order_. 

### Block shuffling

Fields can be grouped with parentheses. Grouped fields will be shuffled as a block, i.e. the first field in the block will be shuffled with all other standalone/first block fields and the other fields in the block will be inserted after the first field in the given order.

For example, let's assume there are seven questions, _b1_ to _b7_, but the questions 1-3 and 6-7 should always stay together. To randomize them, add
> `@SHUFFLE-FIELDS-SURVEY="(b1,b2,b3),b4,b5,(b6,b7)"`  
> `@HIDDEN-SURVEY @READONLY`

to the text field that will capture the order of the actual displayed fields. Shuffle results might then be: _b5-b1+b2+b3-b6+b7-b4_ or _b6+q7-b1+b2+b3-b5-b4_. Plus is used as in-block delimiter instead of the hyphen.  
It must be ensured that parenthesis are matched and not nested. Field names and blocks must be separated by commas, as shown in the example above.


When the survey (or data entry form) loads, the question order is shuffled and the displayed field order is entered into the field with the action tag. On survey pages with field numbers, the original order is preserved.

When a survey page is rendered that already has (valid) data in the field holding the field order, then this order will be replicated. If the stored data is invalid, then no field reordering will occur.

A demo project can be downloaded [here](https://raw.githubusercontent.com/grezniczek/redcap_field_shuffle/main/demo/FieldShuffleDemo.REDCap.xml) (file hosted on GitHub).

## Changelog

Version | Comment
------- | -------------
1.1.1   | Add action tag descriptions.
1.1.0   | New feature: Support for block shuffling.
1.0.3   | Lowered version requirements (REDCap 11.4.4, EM Framework 8).
1.0.2   | EM renamed to 'Field Shuffle'.
1.0.1   | Bugfix: Question numbers are now in correct order.
1.0.0   | Initial release.