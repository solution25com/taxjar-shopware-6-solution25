<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Api\ApiDefinition\Generator;

use http\Exception\RuntimeException;
use OpenApi\Annotations\License;
use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Parameter;
use Shopware\Core\Framework\Api\ApiDefinition\ApiDefinitionGeneratorInterface;
use Shopware\Core\Framework\Api\ApiDefinition\DefinitionService;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\OpenApi\OpenApiDefinitionSchemaBuilder;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\OpenApi\OpenApiSchemaBuilder;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelDefinitionInterface;

/**
 * @internal
 *
 * @phpstan-import-type Api from DefinitionService
 * @phpstan-import-type OpenApiSpec from DefinitionService
 */
#[Package('framework')]
class StoreApiGenerator implements ApiDefinitionGeneratorInterface
{
    final public const FORMAT = 'openapi-3';
    private const OPERATION_KEYS = [
        'get',
        'post',
        'put',
        'patch',
        'delete',
    ];

    private readonly string $schemaPath;

    /**
     * @param array{Framework: array{path: string}} $bundles
     *
     * @internal
     */
    public function __construct(
        private readonly OpenApiSchemaBuilder $openApiBuilder,
        private readonly OpenApiDefinitionSchemaBuilder $definitionSchemaBuilder,
        array $bundles,
        private readonly BundleSchemaPathCollection $bundleSchemaPathCollection,
    ) {
        $this->schemaPath = $bundles['Framework']['path'] . '/Api/ApiDefinition/Generator/Schema/StoreApi';
    }

    public function supports(string $format, string $api): bool
    {
        return $format === self::FORMAT && $api === DefinitionService::STORE_API;
    }

    public function generate(array $definitions, string $api, string $apiType, ?string $bundleName): array
    {
        $openApi = new OpenApi([
            'openapi' => '3.1.0',
        ]);
        $this->openApiBuilder->enrich($openApi, $api);

        $forSalesChannel = $api === DefinitionService::STORE_API;

        ksort($definitions);

        foreach ($definitions as $definition) {
            if (!$definition instanceof EntityDefinition) {
                continue;
            }

            if (!$this->shouldDefinitionBeIncluded($definition)) {
                continue;
            }

            $onlyReference = $this->shouldIncludeReferenceOnly($definition, $forSalesChannel);

            $schema = $this->definitionSchemaBuilder->getSchemaByDefinition($definition, $this->getResourceUri($definition), $forSalesChannel, $onlyReference);

            $openApi->components->merge($schema);
        }

        $this->addGeneralInformation($openApi);
        $this->addContentTypeParameter($openApi);

        $data = json_decode($openApi->toJson(), true, 512, \JSON_THROW_ON_ERROR);
        $data['paths'] ??= [];

        $schemaPaths = [$this->schemaPath];

        if (!empty($bundleName)) {
            $schemaPaths = array_merge([$this->schemaPath . '/components', $this->schemaPath . '/tags'], $this->bundleSchemaPathCollection->getSchemaPaths($api, $bundleName));
        } else {
            $schemaPaths = array_merge($schemaPaths, $this->bundleSchemaPathCollection->getSchemaPaths($api, $bundleName));
        }

        $loader = new OpenApiFileLoader($schemaPaths);

        $preFinalSpecs = $this->mergeComponentsSchemaRequiredFieldsRecursive($data, $loader->loadOpenapiSpecification());
        /** @var OpenApiSpec $finalSpecs */
        $finalSpecs = array_replace_recursive($data, $preFinalSpecs);

        $this->resolveParameterGroups($finalSpecs);

        return $finalSpecs;
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, EntityDefinition>|array<string, EntityDefinition&SalesChannelDefinitionInterface> $definitions
     *
     * @return never
     */
    public function getSchema(array $definitions): array
    {
        throw new RuntimeException();
    }

    private function shouldDefinitionBeIncluded(EntityDefinition $definition): bool
    {
        if (preg_match('/_translation$/', $definition->getEntityName())) {
            return false;
        }

        if (mb_strpos($definition->getEntityName(), 'version') === 0) {
            return false;
        }

        return true;
    }

    private function shouldIncludeReferenceOnly(EntityDefinition $definition, bool $forSalesChannel): bool
    {
        $class = new \ReflectionClass($definition);
        if ($class->isSubclassOf(MappingEntityDefinition::class)) {
            return true;
        }

        if ($forSalesChannel && !is_subclass_of($definition, SalesChannelDefinitionInterface::class)) {
            return true;
        }

        return false;
    }

    private function getResourceUri(EntityDefinition $definition, string $rootPath = '/'): string
    {
        return ltrim('/', $rootPath) . '/' . str_replace('_', '-', $definition->getEntityName());
    }

    private function addGeneralInformation(OpenApi $openApi): void
    {
        $openApi->info->description = 'This endpoint reference contains an overview of all endpoints comprising the Shopware Store API';
        $openApi->info->license = new License([
            'name' => 'MIT',
            'url' => 'https://github.com/shopware/shopware/blob/trunk/LICENSE',
        ]);
    }

    private function addContentTypeParameter(OpenApi $openApi): void
    {
        $openApi->components->parameters = [
            new Parameter([
                'parameter' => 'contentType',
                'name' => 'Content-Type',
                'in' => 'header',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                    'default' => 'application/json',
                ],
                'description' => 'Content type of the request',
            ]),
            new Parameter([
                'parameter' => 'accept',
                'name' => 'Accept',
                'in' => 'header',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                    'default' => 'application/json',
                ],
                'description' => 'Accepted response content types',
            ]),
        ];

        if (!is_iterable($openApi->paths)) {
            return;
        }

        foreach ($openApi->paths as $path) {
            foreach (self::OPERATION_KEYS as $key) {
                $operation = $path->$key;

                if (!$operation instanceof Operation) {
                    continue;
                }

                if (!\is_array($operation->parameters)) {
                    $operation->parameters = [];
                }

                array_push($operation->parameters, [
                    '$ref' => '#/components/parameters/contentType',
                ], [
                    '$ref' => '#/components/parameters/accept',
                ]);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $specsFromDefinition
     * @param array<string, array<string, mixed>> $specsFromStaticJsonDefinition
     *
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentsSchemaRequiredFieldsRecursive(array $specsFromDefinition, array $specsFromStaticJsonDefinition): array
    {
        foreach ($specsFromDefinition['components']['schemas'] as $key => $value) {
            if (isset($specsFromStaticJsonDefinition['components']['schemas'][$key]['required']) && isset($specsFromDefinition['components']['schemas'][$key]['required'])) {
                $specsFromStaticJsonDefinition['components']['schemas'][$key]['required']
                    = array_merge_recursive(
                        $specsFromStaticJsonDefinition['components']['schemas'][$key]['required'],
                        $specsFromDefinition['components']['schemas'][$key]['required']
                    );
            }
        }

        return $specsFromStaticJsonDefinition;
    }

    /**
     * [WARNING] Please refrain from using this functionality in new code. It is a workaround to reduce duplication of
     * the criteria parameter groups and may be removed in the future.
     *
     * OpenAPI specification does not support groups of parameters as reusable components.
     * As in Shopware has a number of GET routes that support passing criteria as a set of parameters,
     * describing them in the OpenAPI spec leads to a lot of duplication.
     *
     * This methods adds support for a custom extension that allows describing parameter groups in the components
     * and referencing them in the separate paths as a group. Those groups will be resolved and replaced with the actual parameters.
     *
     * Example:
     *
     * ```json
     * {
     *   "components": {
     *     "x-parameter-groups": {
     *       "pagination": [
     *         {
     *           "name": "limit",
     *           "in": "query",
     *           "required": false,
     *            ... usual parameter properties
     *         },
     *         {
     *           "name": "page",
     *           ... usual parameter properties
     *         }
     *       ]
     *     }
     *   },
     *   "paths": {
     *     "/product": {
     *       "get": {
     *         "parameters": [
     *           {
     *             "x-parameter-group": "pagination"
     *           },
     *           ... other parameters
     *         ]
     *         ... usual operation properties
     *       }
     *     }
     *   }
     * }
     * ```
     *
     * @param OpenApiSpec $specs
     */
    private function resolveParameterGroups(array &$specs): void
    {
        if (!isset($specs['paths']) || !\is_array($specs['paths'])) {
            return;
        }

        // this is a custom extension that is not supported by the OpenAPI spec
        // it has to be processed and removed before the final output
        $parameterGroups = $specs['components']['x-parameter-groups'] ?? [];
        unset($specs['components']['x-parameter-groups']);

        foreach ($specs['paths'] as &$pathDefinition) {
            foreach ($pathDefinition as &$operation) {
                if (!isset($operation['parameters']) || !\is_array($operation['parameters'])) {
                    continue;
                }

                $newParams = [];
                $hasGroup = false;

                foreach ($operation['parameters'] as $parameter) {
                    if (isset($parameter['x-parameter-group'])) {
                        $hasGroup = true;
                        $groupNames = (array) $parameter['x-parameter-group'];

                        foreach ($groupNames as $groupName) {
                            if (isset($parameterGroups[$groupName])) {
                                array_push($newParams, ...$parameterGroups[$groupName]);
                            }
                        }
                    } else {
                        $newParams[] = $parameter;
                    }
                }

                if ($hasGroup) {
                    $operation['parameters'] = $newParams;
                }
            }
        }
    }
}
