<?php

namespace Tests\Support;

use App\Ocr\ReceiptDocument;
use App\Ocr\ReceiptExtraction;
use App\Ocr\ReceiptOcrProvider;
use Closure;
use Throwable;

/**
 * A provider that plays back a script: each call takes the next step, which is a
 * ReceiptExtraction to return, a Throwable to throw, or a closure (given the document) that
 * returns one of those. The last step repeats. It records every document it was given.
 */
class ScriptedOcrProvider implements ReceiptOcrProvider
{
    /** @var list<ReceiptDocument> */
    public array $documents = [];

    private int $call = 0;

    /**
     * @param  list<ReceiptExtraction|Throwable|Closure>  $steps
     */
    public function __construct(private readonly array $steps) {}

    public function name(): string
    {
        return 'scripted';
    }

    public function extract(ReceiptDocument $document): ReceiptExtraction
    {
        $this->documents[] = $document;
        $step = $this->steps[min($this->call++, count($this->steps) - 1)];

        if ($step instanceof Closure) {
            $step = $step($document);
        }

        if ($step instanceof Throwable) {
            throw $step;
        }

        return $step;
    }

    public function calls(): int
    {
        return count($this->documents);
    }

    /**
     * A well-formed extraction with the given values (each at high confidence).
     *
     * @param  array<string, mixed>  $values
     */
    public static function extraction(array $values = []): ReceiptExtraction
    {
        $values += ['merchant' => 'Kedai Runcit Ali', 'date' => '2026-09-20', 'total' => '25.90'];
        $fields = [];

        foreach ($values as $name => $value) {
            $fields[$name] = ['value' => $value, 'confidence' => 0.95];
        }

        return new ReceiptExtraction($fields, 'scripted', 'scripted-1');
    }
}
