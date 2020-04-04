<?php
namespace PoP\API\FieldResolvers;

use PoP\API\Cache\CacheTypes;
use PoP\API\ComponentConfiguration;
use PoP\ComponentModel\State\ApplicationState;
use PoP\API\Schema\SchemaDefinition;
use PoP\API\TypeResolvers\RootTypeResolver;
use PoP\API\TypeResolvers\SiteTypeResolver;
use PoP\ComponentModel\Schema\SchemaHelpers;
use PoP\API\Facades\PersistedQueryManagerFacade;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\API\Facades\PersistedFragmentManagerFacade;
use PoP\ComponentModel\Facades\Cache\PersistentCacheFacade;
use PoP\ComponentModel\TypeResolvers\TypeResolverInterface;
use PoP\ComponentModel\FieldResolvers\AbstractDBDataFieldResolver;
use PoP\ComponentModel\Facades\Schema\SchemaDefinitionServiceFacade;

class RootFieldResolver extends AbstractDBDataFieldResolver
{
    public static function getClassesToAttachTo(): array
    {
        return array(RootTypeResolver::class);
    }

    public static function getFieldNamesToResolve(): array
    {
        return [
            'fullSchema',
            'site',
        ];
    }

    public function getSchemaFieldType(TypeResolverInterface $typeResolver, string $fieldName): ?string
    {
        $types = [
            'fullSchema' => SchemaDefinition::TYPE_OBJECT,
            'site' => SchemaDefinition::TYPE_ID,
        ];
        return $types[$fieldName] ?? parent::getSchemaFieldType($typeResolver, $fieldName);
    }

    public function getSchemaFieldDescription(TypeResolverInterface $typeResolver, string $fieldName): ?string
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        $descriptions = [
            'fullSchema' => $translationAPI->__('The whole API schema, exposing what fields can be queried', ''),
            'site' => $translationAPI->__('This website', ''),
        ];
        return $descriptions[$fieldName] ?? parent::getSchemaFieldDescription($typeResolver, $fieldName);
    }

    protected function getSchemaFieldShapeValues() {
        return [
            SchemaDefinition::ARGVALUE_SCHEMA_SHAPE_FLAT,
            SchemaDefinition::ARGVALUE_SCHEMA_SHAPE_NESTED,
        ];
    }

    public function getSchemaFieldArgs(TypeResolverInterface $typeResolver, string $fieldName): array
    {
        $schemaFieldArgs = parent::getSchemaFieldArgs($typeResolver, $fieldName);
        $translationAPI = TranslationAPIFacade::getInstance();
        switch ($fieldName) {
            case 'fullSchema':
                return array_merge(
                    $schemaFieldArgs,
                    [
                        [
                            SchemaDefinition::ARGNAME_NAME => 'deep',
                            SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_BOOL,
                            SchemaDefinition::ARGNAME_DESCRIPTION => $translationAPI->__('Make a deep introspection of the fields, for all nested objects', ''),
                            SchemaDefinition::ARGNAME_DEFAULT_VALUE => true,
                        ],
                        [
                            SchemaDefinition::ARGNAME_NAME => 'shape',
                            SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_ENUM,
                            SchemaDefinition::ARGNAME_DESCRIPTION => sprintf(
                                $translationAPI->__('How to shape the schema output: \'%s\', in which case all types are listed together, or \'%s\', in which the types are listed following where they appear in the graph', ''),
                                SchemaDefinition::ARGVALUE_SCHEMA_SHAPE_FLAT,
                                SchemaDefinition::ARGVALUE_SCHEMA_SHAPE_NESTED
                            ),
                            SchemaDefinition::ARGNAME_ENUMVALUES => SchemaHelpers::convertToSchemaFieldArgEnumValueDefinitions(
                                $this->getSchemaFieldShapeValues()
                            ),
                            SchemaDefinition::ARGNAME_DEFAULT_VALUE => SchemaDefinition::ARGVALUE_SCHEMA_SHAPE_FLAT,
                        ],
                        [
                            SchemaDefinition::ARGNAME_NAME => 'compressed',
                            SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_BOOL,
                            SchemaDefinition::ARGNAME_DESCRIPTION => $translationAPI->__('Output each resolver\'s schema data only once to compress the output. Valid only when field \'deep\' is `true`', ''),
                            SchemaDefinition::ARGNAME_DEFAULT_VALUE => false,
                        ],
                        [
                            SchemaDefinition::ARGNAME_NAME => 'useTypeName',
                            SchemaDefinition::ARGNAME_TYPE => SchemaDefinition::TYPE_BOOL,
                            SchemaDefinition::ARGNAME_DESCRIPTION => sprintf(
                                $translationAPI->__('Replace type \'%s\' with the actual type name (such as \'Post\')', ''),
                                SchemaDefinition::TYPE_ID
                            ),
                            SchemaDefinition::ARGNAME_DEFAULT_VALUE => true,
                        ],
                    ]
                );
        }

        return $schemaFieldArgs;
    }

    public function resolveValue(TypeResolverInterface $typeResolver, $resultItem, string $fieldName, array $fieldArgs = [], ?array $variables = null, ?array $expressions = null, array $options = [])
    {
        $root = $resultItem;
        switch ($fieldName) {
            case 'fullSchema':
                // Attempt to retrieve from the cache, if enabled
                if ($useCache = ComponentConfiguration::useSchemaDefinitionCache()) {
                    $persistentCache = PersistentCacheFacade::getInstance();
                    // Use different caches for the normal and namespaced schemas,
                    // or it throws exception if switching without deleting the cache (eg: when passing ?use_namespace=1)
                    $vars = ApplicationState::getVars();
                    $cacheType = $vars['namespace-types-and-interfaces'] ?
                        CacheTypes::NAMESPACED_FULLSCHEMA_DEFINITION :
                        CacheTypes::FULLSCHEMA_DEFINITION;
                    $cacheKey = 'fullSchema';
                }
                if ($useCache) {
                    if ($persistentCache->hasCache($cacheKey, $cacheType)) {
                        $schemaDefinition = $persistentCache->getCache($cacheKey, $cacheType);
                    }
                }
                if (!$schemaDefinition) {
                    $schemaDefinitionService = SchemaDefinitionServiceFacade::getInstance();
                    $stackMessages = [
                        'processed' => [],
                    ];
                    $generalMessages = [
                        'processed' => [],
                    ];
                    $rootTypeSchemaKey = $schemaDefinitionService->getTypeSchemaKey($typeResolver);
                    // Normalize properties in $fieldArgs with their defaults
                    // By default make it deep. To avoid it, must pass argument (deep:false)
                    // By default, use the "flat" shape
                    $schemaOptions = array_merge(
                        $options,
                        [
                            'deep' => isset($fieldArgs['deep']) ? $fieldArgs['deep'] : true,
                            'compressed' => isset($fieldArgs['compressed']) ? $fieldArgs['compressed'] : true,
                            'shape' => isset($fieldArgs['shape']) && in_array(strtolower($fieldArgs['shape']), $this->getSchemaFieldShapeValues()) ? strtolower($fieldArgs['shape']) : SchemaDefinition::ARGVALUE_SCHEMA_SHAPE_FLAT,
                            'useTypeName' => isset($fieldArgs['useTypeName']) ? $fieldArgs['useTypeName'] : true,
                        ]
                    );
                    // If it is flat shape, all types will be added under $generalMessages
                    $isFlatShape = $schemaOptions['shape'] == SchemaDefinition::ARGVALUE_SCHEMA_SHAPE_FLAT;
                    if ($isFlatShape) {
                        $generalMessages[SchemaDefinition::ARGNAME_TYPES] = [];
                    }
                    $typeSchemaDefinition = $typeResolver->getSchemaDefinition($stackMessages, $generalMessages, $schemaOptions);
                    $schemaDefinition[SchemaDefinition::ARGNAME_TYPES] = $typeSchemaDefinition;

                    // Add the queryType
                    $schemaDefinition[SchemaDefinition::ARGNAME_QUERY_TYPE] = $rootTypeSchemaKey;

                    // Move from under Root type to the top: globalDirectives and globalFields (renamed as "functions")
                    $schemaDefinition[SchemaDefinition::ARGNAME_GLOBAL_FIELDS] = $typeSchemaDefinition[$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_FIELDS];
                    $schemaDefinition[SchemaDefinition::ARGNAME_GLOBAL_CONNECTIONS] = $typeSchemaDefinition[$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_CONNECTIONS];
                    $schemaDefinition[SchemaDefinition::ARGNAME_GLOBAL_DIRECTIVES] = $typeSchemaDefinition[$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_DIRECTIVES];
                    unset($schemaDefinition[SchemaDefinition::ARGNAME_TYPES][$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_FIELDS]);
                    unset($schemaDefinition[SchemaDefinition::ARGNAME_TYPES][$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_CONNECTIONS]);
                    unset($schemaDefinition[SchemaDefinition::ARGNAME_TYPES][$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_DIRECTIVES]);

                    // Retrieve the list of all types from under $generalMessages
                    if ($isFlatShape) {
                        $typeFlatList = $generalMessages[SchemaDefinition::ARGNAME_TYPES];

                        // Remove the globals from the Root
                        unset($typeFlatList[$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_FIELDS]);
                        unset($typeFlatList[$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_CONNECTIONS]);
                        unset($typeFlatList[$rootTypeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_DIRECTIVES]);

                        // Because they were added in reverse way, reverse it once again, so that the first types (eg: Root) appear first
                        $schemaDefinition[SchemaDefinition::ARGNAME_TYPES] = array_reverse($typeFlatList);

                        // Add the interfaces to the root
                        $interfaces = [];
                        foreach ($schemaDefinition[SchemaDefinition::ARGNAME_TYPES] as $typeName => $typeDefinition) {
                            if ($typeInterfaces = $typeDefinition[SchemaDefinition::ARGNAME_INTERFACES]) {
                                $interfaces = array_merge(
                                    $interfaces,
                                    (array)$typeInterfaces
                                );
                                // Keep only the name of the interface under the type
                                $schemaDefinition[SchemaDefinition::ARGNAME_TYPES][$typeName][SchemaDefinition::ARGNAME_INTERFACES] = array_keys((array)$schemaDefinition[SchemaDefinition::ARGNAME_TYPES][$typeName][SchemaDefinition::ARGNAME_INTERFACES]);
                            }
                        }
                        $schemaDefinition[SchemaDefinition::ARGNAME_INTERFACES] = $interfaces;
                    }

                    // Add the Fragment Catalogue
                    $fragmentCatalogueManager = PersistedFragmentManagerFacade::getInstance();
                    $persistedFragments = $fragmentCatalogueManager->getPersistedFragmentsForSchema();
                    $schemaDefinition[SchemaDefinition::ARGNAME_PERSISTED_FRAGMENTS] = $persistedFragments;

                    // Add the Query Catalogue
                    $queryCatalogueManager = PersistedQueryManagerFacade::getInstance();
                    $persistedQueries = $queryCatalogueManager->getPersistedQueriesForSchema();
                    $schemaDefinition[SchemaDefinition::ARGNAME_PERSISTED_QUERIES] = $persistedQueries;

                    // Store in the cache
                    if ($useCache) {
                        $persistentCache->storeCache($cacheKey, $cacheType, $schemaDefinition);
                    }
                }

                return $schemaDefinition;
            case 'site':
                return $root->getSite()->getID();
        }

        return parent::resolveValue($typeResolver, $resultItem, $fieldName, $fieldArgs, $variables, $expressions, $options);
    }

    public function resolveFieldTypeResolverClass(TypeResolverInterface $typeResolver, string $fieldName, array $fieldArgs = []): ?string
    {
        switch ($fieldName) {
            case 'site':
                return SiteTypeResolver::class;
        }

        return parent::resolveFieldTypeResolverClass($typeResolver, $fieldName, $fieldArgs);
    }
}
