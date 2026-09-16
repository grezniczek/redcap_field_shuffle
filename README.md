# Field Shuffle

[![DOI](https://zenodo.org/badge/DOI/10.5281/zenodo.22800062.svg)](https://doi.org/10.5281/zenodo.22800062)

Field Shuffle is a REDCap External Module that puts fields into a random order.

## Installation

- Install this module from the REDCap External Module Repository and enable it.

Manual installation:

- Clone this repo into `<redcap-root>/modules/redcap_field_shuffle_v<version-number>`.
- Go to _Control Center > External Modules > Manage_ and enable 'Field Shuffle'.

## Configuration

A **debug** mode can be enabled in the module's project settings. When enabled, some information about the module's actions that may be useful for troubleshooting is output to the browser console.

## Usage

The module provides five action tags:

| Action tag | Surface | Purpose |
| --- | --- | --- |
| `@SHUFFLE-FIELDS-SURVEY` | Survey | Shuffle fields or fixed-order blocks within one already-rendered survey page. |
| `@SHUFFLE-FIELDS-DATAENTRY` | Data entry | Shuffle fields or fixed-order blocks on a data entry form, typically to reproduce a participant's stored survey order. |
| `@SHUFFLE-FIELDS-PAGED` | Survey | Shuffle individual fields among selected positions while preserving the existing page structure. |
| `@SHUFFLE-FIELDS-PAGED-SINGLE` | Survey | Shuffle fields or field groups as units and make every realized unit one survey page. |
| `@SHUFFLE-FIELDS-SH` | Survey | Optionally override the page header for a `PAGED-SINGLE` unit. This companion tag does not generate or store an order. |

### Order-storage field

The first four tags produce a realized order. Apply the selected shuffle tag to the field that should store that order. The storage field must be an unvalidated _Text Box_ field and must not be included in its own shuffle expression. Each shuffle tag requires a quoted expression identifying the controlled fields; the supported grouping syntax depends on the selected mode.

It is recommended to add REDCap's `@HIDDEN-SURVEY` and `@READONLY` action tags to the storage field. `@HIDDEN-SURVEY` is particularly important for `@SHUFFLE-FIELDS-PAGED-SINGLE`: the module omits a hidden storage field from the request-local survey layout so it cannot create an otherwise blank page.

### Within-page shuffling

`@SHUFFLE-FIELDS-SURVEY` randomizes fields on a survey page. The storage field and every shuffled field must be on that same page.

`@SHUFFLE-FIELDS-DATAENTRY` provides equivalent behavior on data entry forms. To reproduce the order seen by a survey participant, place both within-page tags on the same storage field with exactly the same expression.

For example, to randomize four survey questions, _q1_ through _q4_, add the following to another field, such as _displayed_order_:

```text
@SHUFFLE-FIELDS-SURVEY="q1,q2,q3,q4"
@HIDDEN-SURVEY @READONLY
```

### Block shuffling

The two within-page tags support fixed-order blocks. Group fields with parentheses to shuffle them as one unit. The fields inside each block remain in the specified order.

For example, if questions _b1_ through _b3_ and _b6_ through _b7_ should stay together, add the following to the order-storage field:

```text
@SHUFFLE-FIELDS-SURVEY="(b1,b2,b3),b4,b5,(b6,b7)"
@HIDDEN-SURVEY @READONLY
```

Shuffle results might then be _b5-b1+b2+b3-b6+b7-b4_ or _b6+b7-b1+b2+b3-b5-b4_. The stored order uses `+` as the in-block delimiter instead of `-`. Parentheses must be matched and cannot be nested. Separate field names and blocks with commas, as shown above.

When the survey or data entry form loads, the realized field order is written to the storage field. On surveys, question-number cells remain in their display positions, so automatic numbering stays sequential after the shuffle.

When a survey page is rendered that already has (valid) data in the field holding the field order, then this order will be replicated. If the stored data is invalid, then no field reordering will occur.

### Multi-page survey shuffling

The module also provides two survey-only modes that reorder effective instrument metadata before REDCap builds its survey pages. Enable REDCap's survey setting to display each section on a separate page when using these modes. REDCap remains responsible for navigation, validation, branching logic, calculations, piping, Save & Return Later, and survey completion.

#### Fixed page structure: `@SHUFFLE-FIELDS-PAGED`

Use `@SHUFFLE-FIELDS-PAGED` to shuffle complete field definitions among selected field slots while keeping section-header positions and non-selected fields fixed:

> `@SHUFFLE-FIELDS-PAGED="q1,q2,q3,q4,q5,q6"`

Every configured item must be one field. Parenthesized groups and `~` are not supported in this mode. Fields may be scattered across multiple pages.

#### One shuffle unit per page: `@SHUFFLE-FIELDS-PAGED-SINGLE`

Use `@SHUFFLE-FIELDS-PAGED-SINGLE` to make each configured field or parenthesized group one survey page:

> `@SHUFFLE-FIELDS-PAGED-SINGLE="q1,(q2,q3~q4),q5,q6"`

Top-level items are shuffled. Within parentheses, comma joins fixed-order subsequences and `~` shuffles fields within one subsequence. In the example, `q2` remains first while `q3` and `q4` are shuffled. The whole parenthesized unit stays together on one page.

Fixed fields split a configuration into contiguous regions. Units are shuffled independently within each region so fixed fields remain in place and differently sized units cannot cross a fixed-field boundary. Grouped fields must be contiguous in the original instrument metadata.

Add `@HIDDEN-SURVEY` to the order-storage field. In `PAGED-SINGLE` mode the module omits such a control field from the request-local survey layout while continuing to store its value server-side; this prevents it from creating a blank page.

#### Page headers

Place `@SHUFFLE-FIELDS-SH` on any field in a `PAGED-SINGLE` unit to override that unit's page header:

> `@SHUFFLE-FIELDS-SH="Question"`

Normal REDCap piping is supported, including an MLM-translated field label:

> `@SHUFFLE-FIELDS-SH="[question_header:label]"`

If several fields in one unit carry the tag, the first in original metadata order wins and the module logs a warning. Without an override, the first natural section header in original metadata order is used. If neither exists, a blank page-breaking header is generated. The tag is ignored outside a `PAGED-SINGLE` unit. Live piping is not supported.

#### Persistence and validation

The tagged Text Box field remains the source of truth. An empty field receives one generated order; a populated valid field is replayed exactly, including orders supplied externally. Singleton units are separated with `-`, fixed group subsequences with `+`, and realized within-subsequence shuffles with `~`.

Paged configurations are validated before use. Missing or duplicate fields, malformed grammar, overlapping configurations, non-contiguous groups, cross-region stored orders, and stale stored values are rejected and logged. Fields controlled by `@SHUFFLE-FIELDS-PAGED-SINGLE` cannot also be controlled by `@SHUFFLE-FIELDS-SURVEY`. An invalid stored order is never silently replaced with a new randomization.

A demo project can be downloaded [here](https://raw.githubusercontent.com/grezniczek/redcap_field_shuffle/main/demo/FieldShuffleDemo.REDCap.xml) (file hosted on GitHub).

## Release History

See [CHANGELOG.md](CHANGELOG.md) for version history and notable changes.

## How to cite this work

Please use the citation generated from [CITATION.cff](CITATION.cff). On [GitHub](https://github.com/grezniczek/redcap_field_shuffle), select **Cite this repository** for ready-to-use citation formats.


---

## AI assistance

Development of this project has made extensive use of AI assistance. AI tools, primarily ChatGPT by OpenAI, have been used throughout the development process, including for discussion and refinement of design and architecture, implementation and refactoring of code, debugging and review, and preparation and revision of documentation.

The extent and nature of this assistance vary across the project and are not attributed to individual commits. AI-generated suggestions and contributions are reviewed, adapted, and integrated as part of the normal development process.

Responsibility for the design, implementation, maintenance, and released software remains entirely with the project maintainer.
