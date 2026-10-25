<?php

declare(strict_types=1);

namespace Orion\Elasticsearch;

use InvalidArgumentException;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\VectorMetric;
use Orion\Support\Assert;

use function array_flip;
use function array_replace_recursive;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Neutral schema to Elasticsearch mapping and back. The native mapping is the truth `inspect()` reports; the neutral
 * attributes are kept in the mapping's `_meta` only to tell apart what Elasticsearch cannot (sortable versus
 * facetable both mean `doc_values`), and only where the native mapping agrees. Anything with no neutral equivalent
 * (a `float` rather than a `double`, an analyzer Orion did not create) comes back as {@see FieldType::Unknown}, so a
 * plan touching it fails closed.
 */
final class Mapping
{
    /** ISO 639 hints to Elasticsearch's built-in language analyzers, one to one so `inspect()` can read them back. */
    private const array LANGUAGES = [
        'ar' => 'arabic', 'bg' => 'bulgarian', 'ca' => 'catalan', 'cs' => 'czech', 'da' => 'danish', 'de' => 'german',
        'el' => 'greek', 'en' => 'english', 'es' => 'spanish', 'eu' => 'basque', 'fa' => 'persian', 'fi' => 'finnish',
        'fr' => 'french', 'ga' => 'irish', 'gl' => 'galician', 'hi' => 'hindi', 'hu' => 'hungarian', 'hy' => 'armenian',
        'id' => 'indonesian', 'it' => 'italian', 'lt' => 'lithuanian', 'lv' => 'latvian', 'nl' => 'dutch',
        'no' => 'norwegian', 'pt' => 'portuguese', 'pt-br' => 'brazilian', 'ro' => 'romanian', 'ru' => 'russian',
        'sv' => 'swedish', 'th' => 'thai', 'tr' => 'turkish',
    ];
    private const array METRICS = ['cosine' => 'cosine', 'dot_product' => 'dot_product', 'euclidean' => 'l2_norm'];
    private const string FOLDED = '/^orion_(?:([a-z]{2,3}(?:-[a-z0-9]{2,8})?)_)?folded\z/';

    /**
     * The create-index body: mappings, the analyzers folding needs, then the schema's `native('elasticsearch')`
     * settings (`settings` and `mappings` keys) merged over them.
     *
     * @return array<string, mixed>
     */
    public static function create(IndexSchema $schema, string $engine): array
    {
        $body = [
            'settings' => ['analysis' => ['analyzer' => self::analyzers($schema->fields)]],
            'mappings' => self::mappings($schema->fields),
        ];
        if ($body['settings']['analysis']['analyzer'] === []) {
            unset($body['settings']);
        }

        return array_replace_recursive($body, $schema->native[$engine] ?? []);
    }

    /**
     * @param list<Field> $fields
     * @return array{dynamic: string, _meta: array<string, mixed>, properties: array<string, mixed>}
     */
    public static function mappings(array $fields): array
    {
        return ['dynamic' => 'strict', '_meta' => ['orion' => ['fields' => Field::mapToArray($fields)]], 'properties' => self::properties($fields)];
    }

    /**
     * @param list<Field> $fields
     * @return array<string, mixed>
     */
    public static function properties(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field->name] = self::property($field);
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $mapping the index's `mappings`
     * @return list<Field>
     */
    public static function fields(array $mapping): array
    {
        $meta = $mapping['_meta']['orion']['fields'] ?? [];

        return self::read(is_array($mapping['properties'] ?? null) ? $mapping['properties'] : [], is_array($meta) ? $meta : []);
    }

    /**
     * @param list<Field> $fields
     * @return array<string, array<string, mixed>>
     */
    private static function analyzers(array $fields): array
    {
        $analyzers = [];
        foreach ($fields as $field) {
            if ($field->type === FieldType::Text && $field->folding) {
                $filters = ['lowercase', 'asciifolding'];
                if ($field->language !== null) {
                    $filters[] = ['type' => 'stemmer', 'language' => self::language($field->language)];
                }
                $analyzers[self::analyzer($field)] = ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => $filters];
            }
            $analyzers = [...$analyzers, ...self::analyzers($field->fields)];
        }

        return $analyzers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function property(Field $field): array
    {
        $flags = ['index' => $field->filterable, 'doc_values' => $field->sortable || $field->facetable];

        return match ($field->type) {
            FieldType::Text => ['type' => 'text', ...(self::analyzer($field) === null ? [] : ['analyzer' => self::analyzer($field)])],
            FieldType::Keyword => ['type' => 'keyword', ...$flags],
            FieldType::Integer => ['type' => 'long', ...$flags],
            FieldType::Float => ['type' => 'double', ...$flags],
            FieldType::Date => ['type' => 'date', ...$flags],
            FieldType::Boolean => ['type' => 'boolean', 'index' => $field->filterable, 'doc_values' => $field->facetable],
            FieldType::Geo => ['type' => 'geo_point', 'index' => $field->filterable, 'doc_values' => $field->sortable],
            FieldType::Vector => ['type' => 'dense_vector', 'dims' => $field->dims, 'index' => true, 'similarity' => self::METRICS[($field->metric ?? VectorMetric::Cosine)->value]],
            FieldType::Object => ['type' => 'object', 'properties' => self::properties($field->fields)],
            FieldType::Nested => ['type' => 'nested', 'properties' => self::properties($field->fields)],
            FieldType::Unknown => throw new InvalidArgumentException(sprintf('The field %s has no neutral type.', Assert::show($field->name))),
        };
    }

    private static function analyzer(Field $field): ?string
    {
        if ($field->folding) {
            return $field->language === null ? 'orion_folded' : 'orion_' . $field->language . '_folded';
        }

        return $field->language === null ? null : self::language($field->language);
    }

    private static function language(string $iso): string
    {
        return self::LANGUAGES[$iso] ?? throw new InvalidArgumentException(sprintf('Elasticsearch has no built-in analyzer for the language %s.', Assert::show($iso)));
    }

    /**
     * @param array<array-key, mixed> $properties
     * @param array<array-key, mixed> $meta
     * @return list<Field>
     */
    private static function read(array $properties, array $meta): array
    {
        $fields = [];
        foreach ($properties as $name => $property) {
            $name = (string) $name;
            $attributes = is_array($meta[$name] ?? null) ? $meta[$name] : [];
            $fields[] = is_array($property) ? self::field($name, $property, $attributes) : Field::unknown($name);
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $property
     * @param array<array-key, mixed> $meta
     */
    private static function field(string $name, array $property, array $meta): Field
    {
        $type = $property['type'] ?? (isset($property['properties']) ? 'object' : null);
        $index = ($property['index'] ?? true) !== false;
        $docValues = ($property['doc_values'] ?? true) !== false;
        // One doc_values flag means both sortable and facetable; _meta splits it, unless it contradicts the mapping.
        $sortable = $facetable = $docValues;
        $metaSortable = ($meta['sortable'] ?? false) === true;
        $metaFacetable = ($meta['facetable'] ?? false) === true;
        if ($docValues && ($metaSortable || $metaFacetable)) {
            [$sortable, $facetable] = [$metaSortable, $metaFacetable];
        }
        $children = is_array($property['properties'] ?? null) ? $property['properties'] : [];
        $childMeta = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];

        return match ($type) {
            'text' => self::text($name, $property, $meta),
            'keyword' => Field::keyword($name, $index, $sortable, $facetable),
            'long' => Field::integer($name, $index, $sortable, $facetable),
            'double' => Field::float($name, $index, $sortable, $facetable),
            'date' => Field::date($name, $index, $sortable, $facetable),
            'boolean' => Field::boolean($name, $index, $docValues),
            'geo_point' => Field::geo($name, $index, $docValues),
            'dense_vector' => self::vector($name, $property),
            'object' => $children === [] ? Field::unknown($name) : Field::object($name, ...self::read($children, $childMeta)),
            'nested' => $children === [] ? Field::unknown($name) : Field::nested($name, ...self::read($children, $childMeta)),
            default => Field::unknown($name),
        };
    }

    /**
     * @param array<array-key, mixed> $property
     * @param array<array-key, mixed> $meta
     */
    private static function text(string $name, array $property, array $meta): Field
    {
        $analyzer = $property['analyzer'] ?? 'standard';
        $boost = (float) ($meta['boost'] ?? 1.0);
        if ($analyzer === 'standard') {
            return Field::text($name, boost: $boost);
        }
        $languages = array_flip(self::LANGUAGES);
        if (is_string($analyzer) && isset($languages[$analyzer])) {
            return Field::text($name, language: (string) $languages[$analyzer], boost: $boost);
        }
        if (is_string($analyzer) && preg_match(self::FOLDED, $analyzer, $match) === 1) {
            return Field::text($name, language: ($match[1] ?? '') === '' ? null : $match[1], boost: $boost, folding: true);
        }

        return Field::unknown($name);
    }

    /**
     * @param array<array-key, mixed> $property
     */
    private static function vector(string $name, array $property): Field
    {
        $metric = array_flip(self::METRICS)[$property['similarity'] ?? ''] ?? null;
        $dims = $property['dims'] ?? null;
        if ($metric === null || !is_int($dims)) {
            return Field::unknown($name);
        }

        return Field::vector($name, $dims, VectorMetric::from($metric));
    }
}
