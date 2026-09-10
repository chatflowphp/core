# Validation

Interaction rules are resolved through `ValidationRegistry`.

## Built-in Rules

| Alias | Accepts |
| --- | --- |
| `required` | non-empty text |
| `numeric`, `integer` | numeric text |
| `email` | a valid email address |
| `regex:/pattern/` | text matching the pattern |
| `callback` | a callable in the parameters (programmatic use) |

Rules with a parameter use `alias:value`; the parameter reaches the validator as
`['value' => ...]`, or `['pattern' => ...]` for `regex`.

## Custom Validators

```php
use ChatFlow\Validation\ValidatorInterface;

final class PhoneValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $parameters = []): bool
    {
        return \is_string($value) && preg_match('/^\+7\d{10}$/', $value) === 1;
    }
}

$application->getValidationRegistry()->register('phone', PhoneValidator::class);
$application->getValidationRegistry()->register('even', new EvenValidator());
```

Class names are resolved through the container, so validators can have dependencies.

```php
$ctx->ask('Phone?')->validate('phone', 'Use +79991234567.')->handle('savePhone');
```
