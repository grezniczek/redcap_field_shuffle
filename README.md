# Scramble

A REDCap External Module that puts fields into a random order

## Installation

- Install this module from the REDCap External Module Repository and enable it.

Manual installation:

- Clone this repo into `<redcap-root>/modules/redcap_scramble_v<version-number>`.
- Go to _Control Center > External Modules > Manage_ and enable 'Scramble'.

## Configuration

A **debug** mode can be enable in the module's project settings. When enabled, some information about the module's actions that may be useful for troubleshooting is output to the browser console.

## Usage

The module's actions are controlled by **Action Tags**: 

- **`@SCRAMBLE-SURVEY`** will randomize the question order on survey pages. Please note that all fields that are scrambled as well as the field that holds the scramble order **must** be on the same survey page.

- **`@SCRAMBLE-DATAENTRY`** will randomize the question order on data entry pages. This may be useful to see the questions in the same order as viewed by a survey participant. In this case, make sure that both action tags are applied to the same field (holding the order) with the exact same parameters.

Both action tags take should be applied to the field that should hold the question order. It must be a field of type _Text Box_ without any validation. It is recommended to apply the `@HIDDEN-SURVEY` and the `@READONLY` action tags to this field as well.

The `@SCRAMBLE-SURVEY` and `@SCRAMBLE-DATAENTRY` both take a comma-separated list (in quotes) of the variable names of the fields the order of which should be randomized.

For example, let's assume a survey with four questions, _q1_, _q2_, _q3_, and _q4_. To randomize them, add  
> `@SCRAMBLE-SURVEY="q1,q2,q3,q4"`  
> `@HIDDEN-SURVEY @READONLY`

to a further field, e.g., _order_q1_4_. 

When the survey (or data entry form) loads, the question order is scrambled and the displayed field order is entered into the field with the action tag. On survey pages with field numbers, the original order is preserved.

When a survey page is rendered that already has (valid) data in the field holding the field order, then this order will be replicated. If the stored data is invalid, then no field reordering will occur.

## Changelog

Version | Comment
------- | -------------
1.0.1   | Bugfix: Question numbers are now in correct order.
1.0.0   | Initial release.