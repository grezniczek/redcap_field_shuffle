# Feature Request: Paged Field Shuffling for REDCap Field Shuffle EM

(developed by Günther Rezniczek in a chat with ChatGPT, Sept. 15, 2026)

## Summary

Extend the **REDCap Field Shuffle External Module** with two new survey-only shuffle modes that operate before REDCap derives survey pages from the instrument metadata:

- `@SHUFFLE-FIELDS-PAGED`
- `@SHUFFLE-FIELDS-PAGED-SINGLE`

The goal is to support randomized presentation of fields across multi-page REDCap surveys while preserving REDCap's native paging, Previous/Next navigation, branching logic, validation, calculations, piping, MLM, Save & Return Later behavior, survey completion behavior, and other survey functionality.

The implementation should **not build a parallel paging/navigation layer**. Instead, it should adjust the effective instrument metadata before REDCap determines page numbers and page contents, thereby "tricking" REDCap into rendering the randomized layout as though it were the instrument's natural metadata order.

The module's established persistence model remains authoritative: the field carrying the shuffle action tag stores the realized order. If that field is already populated with a valid order, the module reproduces that order rather than generating a new one. This naturally supports orders supplied externally, including through **REDCap Randomization v2**.

---

# 1. Design Principles

The new functionality should follow these principles:

1. **Only positioning changes.**
   - Complete field definitions move as units.
   - Field type, validation, required status, branching logic, calculations, choices, annotations, piping, identifiers, etc. are not changed.
   - `@SHUFFLE-FIELDS-PAGED-SINGLE` additionally adjusts section-header assignment as required to create one page per shuffle unit.

2. **REDCap remains responsible for survey behavior.**
   - REDCap derives pages from the modified metadata.
   - REDCap continues to handle Previous/Next navigation, branching logic, required-field validation, Save & Return Later, review/editing, calculations, piping, MLM, survey completion, etc.

3. **The stored permutation is the source of truth.**
   - If the storage field is empty, a permutation is generated and stored.
   - If it contains a valid order, that exact order is replayed.
   - No reshuffling occurs after an order has been established.
   - Orders written by REDCap Randomization v2 or another mechanism are naturally honored without special integration code.

4. **Existing behavior remains backwards-compatible.**
   - Existing `@SHUFFLE-FIELDS-SURVEY` and `@SHUFFLE-FIELDS-DATAENTRY` semantics remain unchanged.
   - Existing block/group syntax remains valid.
   - Existing dash/plus persistence syntax remains valid and is extended only where necessary for the new in-group shuffle syntax.

5. **Survey-only feature.**
   - No `PAGED-DATAENTRY` variant is planned.
   - REDCap data-entry forms are single-page and do not need an equivalent.

---

# 2. New Action Tag: `@SHUFFLE-FIELDS-PAGED`

## Purpose

Shuffle selected fields across their existing survey positions while keeping the survey's section-header/page structure fixed.

## Example

```text
@SHUFFLE-FIELDS-PAGED="q1,q2,q3,q4,q5,q6"
```

Assume the original instrument metadata is conceptually:

```text
Section A
q1
q2
Section B
q3
q4
Section C
q5
q6
```

A realized shuffle might be:

```text
q5,q2,q6,q1,q4,q3
```

The effective metadata presented to REDCap becomes:

```text
Section A
q5
q2
Section B
q6
q1
Section C
q4
q3
```

REDCap then derives pages normally from that adjusted metadata.

## Semantics

- Section headers remain fixed in their original positions.
- Non-shuffled fields remain fixed in their original positions.
- Selected field slots remain fixed.
- Complete selected field definitions are permuted among those selected slots.
- Fields may be scattered throughout the instrument.
- Multiple non-contiguous regions are allowed.
- Groups are **not supported** for this action tag.

## Why groups are not supported

A grouped block could require multiple consecutive target slots and could cross fixed section-header boundaries. That creates a packing problem and conflicts with the invariant that page structure remains fixed.

Therefore, every configured item in `@SHUFFLE-FIELDS-PAGED` represents exactly one field and occupies exactly one existing selected field slot.

---

# 3. New Action Tag: `@SHUFFLE-FIELDS-PAGED-SINGLE`

## Purpose

Shuffle fields or field groups as atomic units while ensuring that **each realized shuffle unit occupies exactly one REDCap survey page**.

This is the primary mode for use cases such as:

- randomized quiz/test questions,
- one question per page,
- bidirectional navigation through a stable randomized order,
- fixed multi-field blocks that should remain together on one page.

## Example: simple one-field-per-page

```text
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,q2,q3,q4,q5,q6"
```

Each top-level item is a shuffle unit and each realized unit becomes one survey page.

## Example: fixed multi-field unit

```text
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,(q2,q3,q4),q5,q6"
```

Top-level shuffle units are:

```text
q1
(q2,q3,q4)
q5
q6
```

The grouped block remains together on one page in fixed internal order.

---

# 4. Multiple Regions in `PAGED-SINGLE`

Several non-overlapping contiguous regions may exist in one instrument.

Regions are detected automatically from instrument metadata based on the fields referenced by one action-tag configuration.

Example:

```text
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,q2,q3,q8,q9"
```

with metadata:

```text
q1
q2
q3
fixed_a
fixed_b
q8
q9
```

This produces two automatically detected regions:

```text
Region 1: q1,q2,q3
Region 2: q8,q9
```

### Important distinction

A single action tag uses one storage field and therefore represents one persisted shuffle configuration, even if it spans multiple automatically detected regions.

Two action tags on two separate storage fields create two independent shuffle configurations and two independently stored orders.

Example:

```text
[order_a]
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,q2,q3"

[order_b]
@SHUFFLE-FIELDS-PAGED-SINGLE="q8,q9"
```

This yields two independently persisted randomizations.

The implementation should not impose an artificial global limit on the number of regions per instrument.

---

# 5. Page Break Rules for `PAGED-SINGLE`

For every realized shuffle unit:

- a page break must occur immediately before the first displayed field of that unit;
- this includes the first unit in every detected region;
- therefore every unit becomes exactly one REDCap survey page.

Because REDCap internally models a section header as an **attribute of a field**, rather than as an independent metadata row, page-break handling should be implemented by assigning an appropriate section header to the first displayed field of each realized unit.

---

# 6. Section Header Handling

## New Action Tag: `@SHUFFLE-FIELDS-SH`

This optional tag controls the section header used for a `PAGED-SINGLE` shuffle unit.

Examples:

```text
@SHUFFLE-FIELDS-SH="Question"
```

or, using normal REDCap piping:

```text
@SHUFFLE-FIELDS-SH="[question_header:label]"
```

Using `[field_name:label]` is preferred over introducing a separate `-FROM` action tag because it reuses native REDCap piping syntax.

A descriptive field is recommended as a label source when the field exists only to provide translatable header text, but any field may technically be used.

## MLM / localization

Using a field label as the source naturally leverages REDCap MLM translations.

Recommended pattern:

```text
[header_source]
Field type: Descriptive
Field label: localized through MLM

[q1]
@SHUFFLE-FIELDS-SH="[header_source:label]"
```

## Piping

- Normal REDCap piping may be supported.
- Live piping is explicitly **not supported**.

## Placement rule

`@SHUFFLE-FIELDS-SH` may be placed on any field within a `PAGED-SINGLE` shuffle unit.

The first field in the unit, in original metadata order, carrying `@SHUFFLE-FIELDS-SH` supplies the override.

If multiple fields in the same unit carry the tag:

- use the first one in original metadata order;
- log or otherwise surface a warning for the additional tags.

---

# 7. Natural Section Header Precedence

For each realized `PAGED-SINGLE` unit, resolve its page header using this precedence:

1. `@SHUFFLE-FIELDS-SH` override, if present anywhere in the unit.
2. Otherwise, the first natural section header found in the unit in **original metadata order**.
3. Otherwise, a blank generated section header.

After resolving the unit header:

1. remove section headers from all fields in the unit;
2. attach the resolved header to the **first field in realized display order**.

This guarantees exactly one page break for the unit and prevents section headers from splitting a group into multiple pages.

## Example

Original metadata:

```text
q1   section_header="Question A"
q2
q3   section_header="Subheading"
```

Configuration:

```text
(q1~q2~q3)
```

Realized internal order:

```text
q3,q1,q2
```

Effective metadata:

```text
q3   section_header="Question A"
q1   section_header=""
q2   section_header=""
```

The first natural header in the designed unit is preserved, but attached to the first displayed field after shuffling.

For a singleton unit, its natural section header is preserved unless explicitly overridden by `@SHUFFLE-FIELDS-SH`.

---

# 8. Extended Group Grammar for `PAGED-SINGLE`

Existing group syntax remains supported:

```text
(q1,q2,q3)
```

meaning:

> One atomic shuffle unit containing q1, q2, q3 in fixed internal order.

The grammar is extended with `~` to allow **within-unit shuffling**.

## Operator semantics

Inside parentheses:

```text
~  = shuffle these fields
,  = concatenate the resulting parts in fixed order
```

`~` has higher precedence than `,`.

## Examples

```text
(q1,q2,q3)
```

means:

```text
q1 -> q2 -> q3
```

---

```text
(q1,q2~q3)
```

means:

```text
q1 -> shuffle(q2,q3)
```

---

```text
(q1~q2,q3~q4)
```

means:

```text
shuffle(q1,q2) -> shuffle(q3,q4)
```

---

```text
(q1~q2~q3,q4,q5~q6)
```

means:

```text
shuffle(q1,q2,q3) -> q4 -> shuffle(q5,q6)
```

## Formal conceptual grammar

```text
unit
    := field
     | "(" sequence ")"

sequence
    := shuffle_part ("," shuffle_part)*

shuffle_part
    := field ("~" field)*
```

At the top level, commas continue to separate shuffle units.

Example:

```text
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,(q2,q3~q4),q5,q6"
```

Top-level units:

```text
q1
(q2, shuffle(q3,q4))
q5
q6
```

The four top-level units are shuffled; the grouped unit remains on one page and resolves internally according to its own grammar.

---

# 9. Interaction with Existing `@SHUFFLE-FIELDS-SURVEY`

`@SHUFFLE-FIELDS-SURVEY` remains a client-side within-page shuffle mechanism.

The new paged modes operate earlier by changing effective metadata before REDCap constructs survey pages.

Conceptually:

```text
instrument metadata
      |
      v
PAGED / PAGED-SINGLE metadata transformation
      |
      v
REDCap derives pages and renders current page
      |
      v
existing SHUFFLE-FIELDS-SURVEY DOM shuffle, where applicable
```

However, a `PAGED-SINGLE` unit cannot additionally use `@SHUFFLE-FIELDS-SURVEY` to randomize its internal fields.

Internal randomness in a `PAGED-SINGLE` group must be represented directly in the server-side grammar using `~`.

Example:

```text
(q1,q2~q3~q4)
```

This keeps all randomness for a page unit in one deterministic, persisted permutation model.

---

# 10. Branching Logic and Hidden Fields

Branching logic is not modified.

The module changes only field positioning and, for `PAGED-SINGLE`, section-header assignment.

If a field or an entire shuffle unit is hidden by REDCap branching logic for a particular participant, REDCap should handle that condition normally.

The stored permutation remains unchanged.

The module must not:

- remove hidden fields from the permutation,
- generate a new permutation because a field is currently hidden,
- alter branching logic,
- attempt to maintain a separate notion of "visible order".

This ensures that a participant's assigned order remains stable throughout the survey.

---

# 11. Persistence and Stored Order Syntax

The existing persistence model should remain unchanged in principle.

The storage field associated with the shuffle action tag contains the realized order.

Existing dash/plus syntax should be retained and extended with `~` as necessary to represent realized within-block shuffling.

No JSON format or additional storage fields are required.

The persisted value must represent the **realized order**, not merely repeat the requested expression.

Example configuration:

```text
q1,(q2,q3~q4),q5,q6
```

If one realization produces:

```text
q5
(q2,q4,q3)
q1
q6
```

then the stored order must encode that exact realization using the established dash/plus conventions plus `~` where required.

On every subsequent request:

```text
stored order exists
    -> validate against current configuration
    -> reconstruct exact same top-level and internal order
    -> apply identical metadata transformation
```

No reshuffling occurs.

---

# 12. REDCap Randomization v2 Compatibility

No special integration is required.

Because the order-storage field remains authoritative, REDCap Randomization v2 can populate that field with a valid persisted order.

The module then naturally uses it as though it had generated the order itself.

Conceptually:

```text
REDCap Randomization v2
      |
      v
writes valid shuffle-order string
      |
      v
Field Shuffle sees populated storage field
      |
      v
normal validation + replay path
```

This enables predefined or balanced sequences, Latin-square-style order sets, or other externally prepared permutations without adding a dedicated randomization integration layer.

---

# 13. Validation Rules

The module should validate configurations before applying transformations.

At minimum:

## Common

- every configured field must exist;
- fields in a given paged configuration must belong to the same instrument;
- a field must not occur more than once within one configuration;
- stored orders must be compatible with the current configuration;
- invalid or stale stored orders must not be silently rewritten into a new randomization;
- overlapping `@SHUFFLE-FIELDS-PAGED` and `@SHUFFLE-FIELDS-PAGED-SINGLE` ownership of the same field should be rejected;
- conflicting paged configurations should produce a clear warning/error rather than undefined behavior.

## `PAGED`

- grouping syntax is not allowed;
- each listed field represents one movable field definition and one selected destination slot.

## `PAGED-SINGLE`

- grouped fields must form a valid contiguous metadata block in the original instrument;
- one top-level unit becomes one page;
- `~` is valid only inside parenthesized units;
- all fields belonging to one group stay on the same page;
- section-header normalization is applied per unit;
- multiple automatically detected regions are allowed.

## `@SHUFFLE-FIELDS-SH`

- meaningful only for fields that belong to a `PAGED-SINGLE` unit;
- the first tagged field in original unit order wins;
- additional tags in the same unit should trigger a warning.

---

# 14. Stored-Order Validation

The existing replay behavior should be preserved, but paged modes require particularly strict validation because navigation depends on deterministic reconstruction.

A stored order should be accepted only if:

- it contains exactly the configured fields;
- top-level unit membership remains compatible with the configuration;
- grouped fields remain associated with their configured unit;
- any internally shuffled subsets contain the same configured members;
- no configured field is missing or duplicated.

If validation fails:

- do not silently generate and store a replacement order;
- surface a diagnostic/log entry;
- fail safely without applying a potentially inconsistent metadata transformation.

---

# 15. Implementation Architecture

The new functionality should be implemented server-side, before REDCap calculates survey pages.

## Current field-shuffle model

The existing survey shuffle roughly follows:

```text
action tag
    -> parse configured fields/groups
    -> generate proposed shuffle
    -> browser checks storage field
       -> empty: persist proposed order
       -> populated: replay stored order
    -> rearrange rendered DOM rows
```

That is appropriate for fields that already coexist on one rendered page.

## New paged model

Paged modes need the order before REDCap renders the current page.

Therefore:

```text
action tag
    -> parse paged configuration
    -> obtain persisted order server-side
       -> use existing value if valid
       -> otherwise generate + persist new order
    -> transform effective instrument metadata
    -> REDCap derives pages from transformed metadata
    -> REDCap renders/navigates normally
```

The server-side order resolution is essential because the storage field may not be present on the currently rendered survey page.

---

# 16. Suggested Internal Refactoring

Do not force paged behavior through the existing field-DOM mapping code.

Instead, split common parsing/persistence concepts from execution backends.

Conceptually:

```text
Action-tag discovery / common parser
                |
        +-------+-------+
        |               |
        v               v
FieldShuffleConfig   PagedShuffleConfig
        |               |
        v               +-----------------------+
DOM rearrangement       |                       |
                        v                       v
                 PAGED transformer      PAGED-SINGLE transformer
```

Useful separations may include:

- action-tag discovery;
- configuration parsing;
- group/grammar parsing;
- stored-order serialization/deserialization;
- stored-order validation;
- permutation generation;
- metadata-order transformation;
- `PAGED-SINGLE` section-header normalization.

This keeps the existing client-side feature simple while adding the new server-side modes without filling one function with mode-specific branches.

---

# 17. Metadata Transformation: `PAGED`

Implementation model:

1. Obtain the instrument's effective ordered field metadata.
2. Identify the configured selected fields.
3. Record the metadata positions occupied by those selected fields.
4. Reorder the complete field definitions according to the persisted permutation.
5. Place those complete field definitions back into the selected field positions.
6. Leave all non-selected fields and all section-header attributes attached to their original structural positions.
7. Hand the transformed metadata back to REDCap before it derives page structure.

Important:

- move complete field definitions;
- do not merely replace variable names inside another field's metadata;
- only position changes.

---

# 18. Metadata Transformation: `PAGED-SINGLE`

Implementation model:

1. Parse top-level shuffle units and internal group expressions.
2. Detect contiguous regions represented by the configured units.
3. Obtain/replay the persisted outer and internal realized order.
4. Reorder complete field definitions accordingly.
5. For every realized unit:
   - determine its section header using the precedence rules;
   - clear section headers from all fields in the unit;
   - attach the resolved section header to the first realized field;
   - ensure that this field starts a new REDCap survey page.
6. Keep all fields in a grouped unit contiguous.
7. Preserve fields outside the configured regions and their metadata unchanged.
8. Return the transformed metadata before REDCap derives page numbers/page membership.

---

# 19. Behavior Expected to Continue Natively

Because the module transforms metadata rather than reimplementing survey navigation, the following should remain under REDCap control and should continue to work naturally:

- Previous / Next buttons
- stable page numbering during the response
- browser refresh
- validation failures and redisplay
- required fields
- branching logic
- calculations
- piping
- MLM
- Save & Return Later
- survey review/editing
- survey completion
- auto-continue / Survey Queue behavior

These should be verified during testing, but no parallel implementation should be added unless REDCap behavior exposes a specific incompatibility.

---

# 20. Interaction with Survey Question Numbering

Question numbering should remain REDCap-controlled.

The module should not attempt to renumber questions itself.

Testing should verify how REDCap's automatic question numbering behaves after effective metadata reordering and whether the result matches displayed order as expected.

---

# 21. Configuration Examples

## A. Shuffle arbitrary fields across fixed page structure

```text
[shuffle_order]
@SHUFFLE-FIELDS-PAGED="q1,q2,q3,q4,q5,q6"
```

Section headers stay fixed; the six complete field definitions are permuted among their six selected positions.

---

## B. One field per randomized page

```text
[shuffle_order]
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,q2,q3,q4,q5,q6"
```

Each question becomes one page in randomized order.

---

## C. One multi-field block as a page

```text
[shuffle_order]
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,(q2,q3,q4),q5,q6"
```

`q2,q3,q4` remain together, in fixed order, on one page.

---

## D. Internal shuffling inside one page unit

```text
[shuffle_order]
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,(q2,q3~q4),q5,q6"
```

Top-level units are randomized.

Within the grouped page unit:

```text
q2 remains first
q3 and q4 are shuffled
```

---

## E. More complex internal shuffle

```text
[shuffle_order]
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,(q2~q3,q4,q5~q6),q7"
```

The grouped page resolves as:

```text
shuffle(q2,q3)
then q4
then shuffle(q5,q6)
```

---

## F. Section-header override using MLM-compatible field label

```text
[q1]
@SHUFFLE-FIELDS-SH="[question_header:label]"
```

`question_header` may be a descriptive field used solely as a translatable label source.

---

## G. Two independent shuffle domains

```text
[order_a]
@SHUFFLE-FIELDS-PAGED-SINGLE="q1,q2,q3"

[order_b]
@SHUFFLE-FIELDS-PAGED-SINGLE="q8,q9,q10"
```

Each order is generated/stored/replayed independently.

---

# 22. Testing Plan

## Parsing / grammar

Test:

```text
q1,q2,q3
q1,(q2,q3),q4
q1,(q2~q3),q4
q1,(q2,q3~q4),q5
q1,(q2~q3,q4~q5),q6
q1,(q2~q3~q4,q5,q6~q7),q8
```

Verify:

- top-level units;
- fixed-order subsequences;
- `~` precedence;
- internal realized orders;
- round-trip persistence serialization.

## Persistence

Verify:

- empty order field generates once;
- generated order is persisted;
- repeat survey requests reproduce exact order;
- browser refresh preserves order;
- Previous/Next preserves order;
- Save & Return Later preserves order;
- externally populated valid order is honored;
- invalid stored order is rejected rather than regenerated silently.

## `PAGED`

Verify:

- scattered fields;
- multiple regions;
- section headers remain fixed;
- non-selected fields remain fixed;
- branching logic remains attached to original field definitions;
- calculations/validation/choices remain unchanged.

## `PAGED-SINGLE`

Verify:

- singleton units;
- grouped units;
- internal `~` shuffling;
- multiple regions;
- first unit in every region begins a page;
- every subsequent unit begins a page;
- grouped fields remain on one page;
- natural section headers follow unit rules;
- override section headers take precedence;
- blank headers are generated when required;
- duplicate `@SHUFFLE-FIELDS-SH` tags warn and use the first.

## Branching logic

Verify:

- individual hidden fields;
- entirely hidden grouped units;
- branching changes caused by earlier answers;
- backward navigation after visibility changes;
- no reshuffling occurs.

## MLM / piping

Verify:

- `[field:label]` section-header source;
- multilingual field labels;
- ordinary piping;
- explicit non-support of live piping.

## REDCap survey behavior

Verify:

- Previous / Next navigation;
- required validation;
- question numbering;
- page numbering if enabled;
- Save & Return Later;
- review/edit mode;
- survey completion;
- Survey Queue / auto-continue where relevant.

## Conflicts / invalid configuration

Verify:

- nonexistent fields;
- duplicate fields;
- fields from different instruments;
- overlapping paged configurations;
- groups in `PAGED`;
- malformed parentheses;
- malformed `~` expressions;
- non-contiguous grouped fields;
- `@SHUFFLE-FIELDS-SH` outside a `PAGED-SINGLE` unit.

---

# 23. Suggested Implementation Sequence

## Phase 1: Parser and persistence refactor

- Extract reusable configuration parsing from current field-shuffle code.
- Add formal representation of:
  - top-level shuffle units;
  - grouped units;
  - internal shuffle parts using `~`.
- Extend persisted-order parser/serializer while preserving existing dash/plus syntax.
- Add validation and round-trip tests.

## Phase 2: Server-side order resolution

- Add server-side access to the order-storage field.
- Reuse existing-order semantics:
  - populated and valid -> replay;
  - empty -> generate and persist;
  - invalid -> fail safely and log.
- Ensure external values, including Randomization v2 allocations, follow the same path.

## Phase 3: `@SHUFFLE-FIELDS-PAGED`

- Identify hook/injection point before REDCap derives survey pages.
- Implement selected-slot metadata substitution.
- Preserve section-header/page structure.
- Add validation for unsupported groups and overlapping configurations.
- Test with scattered fields and multiple regions.

## Phase 4: `@SHUFFLE-FIELDS-PAGED-SINGLE`

- Implement region detection.
- Implement top-level unit ordering.
- Implement grouped-unit resolution.
- Implement internal `~` shuffling.
- Implement one-page-per-unit section-header normalization.
- Add `@SHUFFLE-FIELDS-SH` handling and precedence.

## Phase 5: Compatibility testing

- branching logic;
- validation;
- calculations;
- piping;
- MLM;
- Previous/Next;
- Save & Return Later;
- survey review;
- survey completion;
- question/page numbering;
- Survey Queue / auto-continue.

## Phase 6: Documentation

Update README with:

- new action tags;
- fixed-slot vs single-unit-page conceptual distinction;
- grammar and precedence examples;
- section-header rules;
- persistence behavior;
- Randomization v2 compatibility note;
- limitations and validation rules;
- migration/backwards-compatibility statement.

---

# 24. User-Facing Conceptual Model

The feature should be explainable simply as follows.

## Existing mode

```text
@SHUFFLE-FIELDS-SURVEY
```

Shuffle fields or field groups **within one already-rendered survey page**.

## New fixed-page mode

```text
@SHUFFLE-FIELDS-PAGED
```

Shuffle selected complete field definitions among their selected positions while leaving section headers and the existing page structure fixed.

## New one-unit-per-page mode

```text
@SHUFFLE-FIELDS-PAGED-SINGLE
```

Shuffle fields or groups as units and make each realized unit exactly one REDCap survey page.

## Optional page-header control

```text
@SHUFFLE-FIELDS-SH="..."
```

Override the section header used for that `PAGED-SINGLE` unit, including support for ordinary REDCap piping such as:

```text
@SHUFFLE-FIELDS-SH="[header_source:label]"
```

---

# 25. Non-Goals

The feature does **not** aim to:

- implement a custom survey navigation engine;
- replace REDCap Previous/Next behavior;
- alter branching logic;
- alter field semantics other than position;
- support live piping in generated section-header text;
- create a data-entry equivalent of paged survey shuffling;
- make `@SHUFFLE-FIELDS-SURVEY` responsible for internal randomization inside a `PAGED-SINGLE` unit.

---

# 26. Overall Rationale

The proposed design extends Field Shuffle without abandoning its existing model:

- action tags define shuffle domains;
- realized order is persisted in ordinary REDCap data;
- a persisted valid order is always authoritative;
- randomization can therefore be generated internally or supplied externally;
- REDCap itself continues to own rendering and survey behavior.

The architectural distinction is clean:

```text
@SHUFFLE-FIELDS-SURVEY
    -> client-side DOM reordering within one rendered page

@SHUFFLE-FIELDS-PAGED
    -> server-side metadata-position substitution before paging

@SHUFFLE-FIELDS-PAGED-SINGLE
    -> server-side shuffled page units with section-header normalization
```

This should allow stable randomized multi-page surveys, including one-question-per-page tests with backward and forward navigation, without reimplementing REDCap's survey engine and without introducing a second persistence model.
