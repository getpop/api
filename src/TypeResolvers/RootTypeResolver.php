<?php
namespace PoP\API\TypeResolvers;

use PoP\API\TypeDataLoaders\RootTypeDataLoader;
use PoP\ComponentModel\Schema\SchemaDefinition;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\ComponentModel\TypeResolvers\AbstractTypeResolver;
use PoP\ComponentModel\Facades\Schema\SchemaDefinitionServiceFacade;

class RootTypeResolver extends AbstractTypeResolver
{
    public const NAME = 'Root';

    public function getTypeName(): string
    {
        return self::NAME;
    }

    public function getSchemaTypeDescription(): ?string
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        return $translationAPI->__('Root type, starting from which the query is executed', 'api');
    }

    public function getID($resultItem)
    {
        $root = $resultItem;
        return $root->getID();
    }

    public function getTypeDataLoaderClass(): string
    {
        return RootTypeDataLoader::class;
    }

    protected function addSchemaDefinition(array $stackMessages, array &$generalMessages, array $options = [])
    {
        parent::addSchemaDefinition($stackMessages, $generalMessages, $options);

        // Only in the root we output the operators and helpers
        $schemaDefinitionService = SchemaDefinitionServiceFacade::getInstance();
        $typeSchemaKey = $schemaDefinitionService->getTypeSchemaKey($this);

        // Add the directives (global)
        $this->schemaDefinition[$typeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_DIRECTIVES] = [];
        $schemaDirectiveResolvers = $this->getSchemaDirectiveResolvers(true);
        foreach ($schemaDirectiveResolvers as $directiveName => $directiveResolver) {
            $this->schemaDefinition[$typeSchemaKey][SchemaDefinition::ARGNAME_GLOBAL_DIRECTIVES][$directiveName] = $this->getDirectiveSchemaDefinition($directiveResolver, $options);
        }

        // Add the fields (global)
        $schemaFieldResolvers = $this->getSchemaFieldResolvers(true);
        foreach ($schemaFieldResolvers as $fieldName => $fieldResolver) {
            $this->addFieldSchemaDefinition($fieldResolver, $fieldName, $stackMessages, $generalMessages, $options);
        }
    }
}

