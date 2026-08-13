# Parameters

Inputs an operator supplies before a run, declared by the developer and validated before anything is queued. Used by the [operator panel](/features/operator-panel) and the `--param` flag.

```php
use Kstmostofa\Backfill\Parameters\Parameter;

public function parameters(): array
{
    return [
        Parameter::ids('order_ids', 'Order IDs')->required()->max(50_000),
        Parameter::select('tone', ['formal' => 'Formal', 'friendly' => 'Friendly']),
        Parameter::boolean('notify', 'Email the customer'),
    ];
}
```

Read them inside `collection()` or `process()`:

```php
$this->parameter('order_ids', []);
$this->parameter('tone', 'formal');
```

## Types

### `Parameter::ids($key, $label = null)`

A pasted list of identifiers, rendered as a textarea.

Splits on commas, semicolons, spaces and newlines — whichever the operator's spreadsheet produced — then trims, drops blanks, and de-duplicates. `"1,,7, 8,\n\n9"` becomes `['1', '7', '8', '9']`.

`max()` caps the number of entries and `min()` sets a floor. **Always set a `max()`** — it is what stops someone pasting a million-line export into a form.

### `Parameter::text($key, $label = null)`

A single-line string, trimmed.

### `Parameter::textarea($key, $label = null)`

A multi-line string, trimmed.

### `Parameter::number($key, $label = null)`

An integer or float. `min()` and `max()` bound the **value** here, not a count.

### `Parameter::boolean($key, $label = null)`

A checkbox. An unticked box is `false` — an answer, not a missing value — so `required()` on a boolean never fails.

### `Parameter::select($key, $options, $label = null)`

A dropdown. `$options` maps stored values to labels. A value outside the list is rejected.

```php
Parameter::select('status', ['active' => 'Active', 'archived' => 'Archived'])
```

## Modifiers

Chainable on every type.

| Modifier | Effect |
| --- | --- |
| `required()` | Reject an empty value |
| `max(int)` | Entry ceiling for `ids`, value ceiling for `number` |
| `min(int)` | Entry floor for `ids`, value floor for `number` |
| `default(mixed)` | Used when nothing is supplied; pre-fills the form |
| `help(string)` | Explanatory text under the label |
| `placeholder(string)` | Placeholder on the input |

## Labels

Omit the label and one is derived from the key: `user_ids` becomes `User ids`. Pass one explicitly when that reads badly — `Parameter::ids('user_ids', 'User IDs')`.

Labels appear in validation messages, so write them for the person reading the error.

## Validating by hand

```php
use Kstmostofa\Backfill\Parameters\ParameterBag;

$result = ParameterBag::validate($backfill, $request->all());

// ['values' => [...], 'errors' => ['Order IDs is required.']]
```

Input not matching a declared parameter is **dropped**, not passed through. The declared set is the whole surface.

`ParameterBag::summarise()` produces the audit string stored on the run:

```
Order IDs: 3 entries, Wording: friendly, Email the customer: no
```

## On the command line

```bash
php artisan backfill:run order-receipts \
    --param=order_ids=1,2,3 \
    --param=tone=friendly
```

Validated identically to the panel. Malformed pairs are rejected rather than guessed at.

## Parameters and resuming

Values are stored on the run as `meta.parameters` and re-applied when it resumes — a resumed run uses the parameters it **started** with, not whatever the caller passes later.

Resuming with different parameters is [refused](/features/operator-panel#parameters-and-resuming). Use `--fresh` to start a new run with new inputs.
