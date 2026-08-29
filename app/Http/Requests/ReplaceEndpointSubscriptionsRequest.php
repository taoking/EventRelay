<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Event\EventTypeValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use LogicException;

final class ReplaceEndpointSubscriptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'types' => ['present', 'array', 'list'],
            'types.*' => ['string'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $types = $this->input('types');

            if (! is_array($types)) {
                return;
            }

            foreach ($types as $index => $type) {
                if (is_string($type) && ! EventTypeValidator::isValid($type)) {
                    $validator->errors()->add(
                        sprintf('types.%s', (string) $index),
                        'The event type has an invalid format.',
                    );
                }
            }
        }];
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        $types = $this->input('types');

        if (! is_array($types)) {
            throw new LogicException('Validated subscription types must be a list.');
        }

        /** @var list<string> $types */
        return $types;
    }
}
