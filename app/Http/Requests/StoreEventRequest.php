<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Event\EventTypeValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;
use LogicException;
use stdClass;

final class StoreEventRequest extends FormRequest
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
            'type' => ['required', 'string'],
            'payload' => ['present'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = $this->input('type');

                if (is_string($type) && ! EventTypeValidator::isValid($type)) {
                    $validator->errors()->add('type', 'The type field has an invalid event type.');
                }
            },
            function (Validator $validator): void {
                if (! array_key_exists('payload', $this->all())) {
                    return;
                }

                if (! $this->rawPayload() instanceof stdClass) {
                    $validator->errors()->add('payload', 'The payload field must be a JSON object.');
                }
            },
        ];
    }

    public function payload(): stdClass
    {
        $payload = $this->rawPayload();

        if (! $payload instanceof stdClass) {
            throw new LogicException('Validated event payload must be a JSON object.');
        }

        return $payload;
    }

    private function rawPayload(): mixed
    {
        try {
            $body = json_decode($this->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $body instanceof stdClass || ! property_exists($body, 'payload')) {
            return null;
        }

        return $body->payload;
    }
}
