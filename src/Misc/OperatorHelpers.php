<?php
namespace PoP\API\Misc;

use Exception;

class OperatorHelpers
{
    protected static function throwNoArrayItemUnderPathException(array $data, string $path)
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        throw new Exception(sprintf(
            $translationAPI->__('Path \'%s\' is not reachable for object: %s', 'pop-component-model'),
            $path,
            json_encode($data)
        ));
    }
    public static function &getPointerToArrayItemUnderPath(array $data, string $path)
    {
        $dataPointer = &$data;

        // Iterate the data array to the provided path.
        foreach (explode(POP_CONSTANT_APIJSONRESPONSE_PATHDELIMITERSYMBOL, $path) as $pathLevel) {
            if (!$dataPointer) {
                // If we reached the end of the array and can't keep going down any level more, then it's an error
                return self::throwNoArrayItemUnderPathException($data, $path);
            } elseif (isset($dataPointer[$pathLevel])) {
                // Retrieve the property under the pathLevel
                $dataPointer = &$dataPointer[$pathLevel];
            } elseif (is_array($dataPointer) && isset($dataPointer[0]) && is_array($dataPointer[0]) && isset($dataPointer[0][$pathLevel])) {
                // If it is an array, then retrieve that property from each element of the array
                $dataPointerArray = array_map(function($item) use($pathLevel) {
                    return $item[$pathLevel];
                }, $dataPointer);
                $dataPointer = &$dataPointerArray;
            } else {
                // We are accessing a level that doesn't exist
                // If we reached the end of the array and can't keep going down any level more, then it's an error
                return self::throwNoArrayItemUnderPathException($data, $path);
            }
        }
        return $dataPointer;
    }
}