<?php

namespace App\Http\Requests;

use App\Generations\Quality;
use App\Generations\Size;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Drop empty reference image fields, so a blank input is not an invalid URL.
     */
    protected function prepareForValidation(): void
    {
        $references = $this->input('reference_images');

        if (is_array($references)) {
            $this->merge([
                'reference_images' => array_values(array_filter(
                    $references,
                    fn (mixed $url) => ! (is_string($url) && trim($url) === '') && $url !== null,
                )),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Limits match Pixelforge's: a prompt of at most 4,000 characters (Laravel
     * counts Unicode characters) and at most 4 http(s) reference image URLs.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * The validated input, typed for CreateGeneration.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>}
     */
    public function generationInput(): array
    {
        return [
            'prompt' => $this->string('prompt')->toString(),
            'size' => $this->string('size')->toString(),
            'quality' => $this->string('quality')->toString(),
            'reference_images' => array_values($this->validated('reference_images') ?? []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'Add at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http or https URL.',
        ];
    }
}
