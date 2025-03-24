<? 
class ProductHandler
{
    public static function getRelationsByProductId($productId)
    {
        $cacheTime = 86400; // Время кэширования в секундах (1 день)
        $cacheId = 'relations_product_' . $productId;
        $cacheDir = '/relations/';

        $cache = Cache::createInstance();

        if ($cache->InitCache($cacheTime, $cacheId, $cacheDir)) {
            $res = $cache->GetVars();
            return $res;
        } elseif ($cache->StartDataCache()) {
            $hlblock = HighloadBlockTable::getById(C::HLBLOCK_COLORS_RELATIONS)->fetch();
            if (!$hlblock) {
                $cache->AbortDataCache();
                throw new \RuntimeException("Хайлоад-блок с ID {C::HLBLOCK_COLORS_RELATIONS} не найден.");
            }

            $entity = HighloadBlockTable::compileEntity($hlblock);
            $entityClass = $entity->getDataClass();

            $result = $entityClass::getList([
                'select' => ['UF_RELATIONS'],
                'filter' => ['UF_PRODUCT' => $productId]
            ])->fetch();
        }

        if ($result) {
            $res = CIBlockElement::GetList(arFilter: ["IBLOCK_ID" => C::IBLOCKID_CATALOG, "ID" => $result['UF_RELATIONS'], "ACTIVE" => "Y", "!PROPERTY_SHOW_IN_CB_VALUE" => "Y"], arSelectFields: ["ID", "CODE", "PREVIEW_PICTURE", "PROPERTY_TSVET_YML"]);
            $items = [];
            while ($item = $res->Fetch()) {
                $items[] = $item;
            }
            $cache->EndDataCache($items);
            return $items;
        } else {
            $cache->AbortDataCache();
            return false;
        }
    }

}