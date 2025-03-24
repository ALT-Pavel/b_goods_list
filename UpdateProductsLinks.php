<?php

namespace App\Agent\Catalog;

use App\Agent\AgentAbstract;
use App\Agent\AgentLog;
use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
use CIBlockElement;
use Bitrix\Main\Data\Cache;
use C;

Loader::includeModule("iblock");
Loader::includeModule("catalog");

final class UpdateProductsLinks extends AgentAbstract
{

    public static function run()
    {
        try {
            if (!Loader::includeModule("iblock") || !Loader::includeModule("highloadblock")) {
                self::errorLog("Не удалось подключить необходимые модули Bitrix.");
            }
            self::updateProducts();
        } catch (\Throwable $e) {
            $data = [
                $e->getFile(),
                $e->getMessage(),
                $e->getLine()
            ];

            self::errorLog($data);
        }

        return __CLASS__ . "::run();";
    }

    private static function updateProducts()
    {
        $brand = "ваш бренд";// свойство для сравнения

        $products = self::getProductList($brand);
        $relations = self::buildProductRelations($products);
        self::saveProductRelations($relations);
    }

    private static function getProductList($brand)
    {
        $arFilter = [
            "IBLOCK_ID" => C::IBLOCKID_CATALOG,
            "ACTIVE" => "Y",
            "!PROPERTY_KATEGORIYA_TOVARA_VALUE" => "архив",
            "PROPERTY_BREND_VALUE" => $brand,
            "!IBLOCK_SECTION_ID" => [1,2,3],
            "!PROPERTY_SHOW_IN_CB_VALUE" => "Y"
        ];

        $arSelect = ["ID", "PROPERTY_CML2_ARTICLE"];
        $products = [];

        $res = CIBlockElement::GetList(arFilter: $arFilter, arSelectFields: $arSelect);
        while ($product = $res->Fetch()) {
            $article = $product["PROPERTY_CML2_ARTICLE_VALUE"];
            $prefix = mb_strstr($article, '-', true);// сравниваем артикулы в которых есть тире

            if ($prefix) {
                $products[] = [
                    "ID" => $product["ID"],
                    "PREFIX" => $prefix . '-'
                ];
            }
        }

        return $products;
    }

    private static function buildProductRelations($products)
    {
        $relations = [];
        $prefixMap = [];

        // Сгруппируем товары по префиксам
        foreach ($products as $product) {
            $prefixMap[$product["PREFIX"]][] = $product["ID"];
        }

        // Формируем связи
        foreach ($products as $product) {
            $prefix = $product["PREFIX"];
            $currentId = $product["ID"];

            if (isset($prefixMap[$prefix])) {
                $relatedIds = array_unique($prefixMap[$prefix]);
                if (!empty($relatedIds) && count($relatedIds) > 1) {
                    $relations[] = [
                        "PRODUCT_ID" => $currentId,
                        "RELATED_IDS" => $relatedIds
                    ];
                }
            }
        }

        return $relations;
    }

    private static function saveProductRelations($relations)
    {
        $hlblock = HighloadBlockTable::getById(C::HLBLOCK_COLORS_RELATIONS)->fetch();
        if (!$hlblock) {
            self::errorLog("Проверьте настройки хайлоад блока!");
            return;
        }

        $entity = HighloadBlockTable::compileEntity($hlblock);
        $entityClass = $entity->getDataClass();
        $cache = Cache::createInstance();
        $cacheDir = '/links/';

        foreach ($relations as $relation) {
            $res = $entityClass::getRow([
                'filter' => [
                        'UF_PRODUCT' => $relation["PRODUCT_ID"],
                    ],
                'select' => ["ID", "UF_PRODUCT"]
            ]);

            if ($res['UF_PRODUCT'] == $relation["PRODUCT_ID"]) {
                $entityClass::update(
                    $res['ID'],
                    ["UF_LINKS" => $relation["RELATED_IDS"]]);
                    $cache->clean('relations_product_' . $relation["PRODUCT_ID"], $cacheDir);
                    foreach ($relation["RELATED_IDS"] as $item) {
                        $cache->clean('relations_product_' . $item, $cacheDir);
                    }
            } else {
                $entityClass::add([
                    "UF_PRODUCT" => $relation["PRODUCT_ID"],
                    "UF_LINKS" => $relation["RELATED_IDS"]
                ]);
            }
        }
    }

    private static function errorLog($data)
    {
        AgentLog::addLog('UpdateProductsLinksError', $data);
    }

    private static function log($data)
    {
        AgentLog::addLog('UpdateProductsLinks', $data);
    }
}
