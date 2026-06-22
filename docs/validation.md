# Validation

Validation is used mostly by scene interactions.

`ValidationRegistry` maps validation rules to validators.

Common rules:

- `required`
- `email`
- `numeric`
- `regex:/pattern/`
- callback validators

Example:

```php
$this->ask('Enter phone')
    ->validate('regex:/^(\+7|7|8)\d{10}$/', 'Use +79991234567 or 89991234567.')
    ->handle([$this, 'handlePhone']);
```

When validation fails, the scene replies with the validation message and keeps the interaction active.

## Custom Validators

Implement `ValidatorInterface` and register it in `ValidationRegistry`.

Use custom validators when:

- the rule is reused in many scenes.
- the rule needs a service.
- the rule is more readable as a named validation.

For one-off checks, handle it inside the scene method.
