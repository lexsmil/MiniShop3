<?php

namespace MiniShop3\Controllers\Api\Manager;

use MiniShop3\Model\msProduct;
use MiniShop3\Model\msProductData;
use MiniShop3\Model\msProductOption;
use MiniShop3\Model\msCategory;
use MiniShop3\Router\Response;
use MiniShop3\Services\FilterConfigManager;
use MODX\Revolution\modX;

/**
 * API controller for category products management
 *
 * Handles product listing, filtering, sorting for category page.
 *
 * @package MiniShop3\Controllers\Api\Manager
 */
class CategoryProductsController
{
    protected modX $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * Get list of products in category with pagination and filtering
     * GET /api/mgr/categories/{id}/products
     *
     * @param array $params URL and query parameters
     * @return array Response
     */
    public function getList(array $params = []): array
    {
        $categoryId = (int)($params['id'] ?? 0);

        if (!$categoryId) {
            return Response::error('Category ID is required', 400)->getData();
        }

        $category = $this->modx->getObject(msCategory::class, $categoryId);
        if (!$category) {
            return Response::error('Category not found', 404)->getData();
        }

        $start = (int)($params['start'] ?? 0);
        $limit = (int)($params['limit'] ?? 20);
        $sortBy = $params['sort'] ?? 'menuindex';
        $sortDir = strtoupper($params['dir'] ?? 'ASC');
        $query = trim($params['query'] ?? '');
        $nested = (bool)($params['nested'] ?? false);

        // Validate sort direction
        if (!in_array($sortDir, ['ASC', 'DESC'])) {
            $sortDir = 'ASC';
        }

        $gridConfig = $this->modx->services->get('ms3_grid_config');
        $gridFields = $gridConfig ? $gridConfig->getGridConfig('category-products', true) : [];
        $optionFields = $gridConfig ? $gridConfig->extractOptionFields($gridFields) : [];

        $c = $this->buildProductListQuery($categoryId, $params, $nested, $optionFields);

        $countQuery = $this->buildProductListQuery($categoryId, $params, $nested, $optionFields);
        $countQuery->select('COUNT(DISTINCT msProduct.id)');
        $countQuery->prepare();
        $countQuery->stmt->execute();
        $total = (int)$countQuery->stmt->fetchColumn();

        $sortField = $this->mapSortField($sortBy, $optionFields);
        $c->sortby($sortField, $sortDir);
        $c->limit($limit, $start);

        $selectParts = [
            'msProduct.*',
            'Data.article',
            'Data.price',
            'Data.old_price',
            'Data.weight',
            'Data.image',
            'Data.thumb',
            'Data.vendor_id',
            'Data.made_in',
            'Data.new',
            'Data.popular',
            'Data.favorite',
        ];
        foreach ($optionFields as $opt) {
            $selectParts[] = "GROUP_CONCAT(DISTINCT `{$opt['alias']}`.value) AS `{$opt['fieldName']}`";
        }
        $c->select($selectParts);
        if (!empty($optionFields)) {
            $c->groupby('msProduct.id');
        }

        $c->prepare();
        $rows = $c->stmt->execute() ? $c->stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $optionFieldNames = array_column($optionFields, 'fieldName');
        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->formatProduct($row, $nested, $optionFieldNames);
        }

        return Response::success([
            'results' => $results,
            'total' => $total
        ])->getData();
    }

    /**
     * Get filters configuration
     * GET /api/mgr/categories/{id}/products/filters
     *
     * @param array $params
     * @return array Response
     */
    public function getFilters(array $params = []): array
    {
        /** @var FilterConfigManager $filterConfigManager */
        $filterConfigManager = $this->modx->services->get('ms3_filter_config');

        if (!$filterConfigManager) {
            return Response::success(['filters' => $this->getDefaultFilters()])->getData();
        }

        $filters = $filterConfigManager->getFilters('category-products', true);

        return Response::success(['filters' => $filters])->getData();
    }

    /**
     * Sort products (drag-drop reordering)
     * POST /api/mgr/categories/{id}/products/sort
     *
     * @param array $params
     * @return array Response
     */
    public function sort(array $params = []): array
    {
        $categoryId = (int)($params['id'] ?? 0);
        $items = $params['items'] ?? [];

        if (!$categoryId) {
            return Response::error('Category ID is required', 400)->getData();
        }

        if (empty($items) || !is_array($items)) {
            return Response::error('Items array is required', 400)->getData();
        }

        $updated = 0;

        foreach ($items as $item) {
            $productId = (int)($item['id'] ?? 0);
            $menuindex = (int)($item['menuindex'] ?? 0);

            if (!$productId) {
                continue;
            }

            $product = $this->modx->getObject(msProduct::class, [
                'id' => $productId,
                'parent' => $categoryId
            ]);

            if ($product) {
                $product->set('menuindex', $menuindex);
                if ($product->save()) {
                    $updated++;
                }
            }
        }

        return Response::success([
            'updated' => $updated
        ], 'Products reordered successfully')->getData();
    }

    /**
     * Multiple product actions (bulk operations)
     * POST /api/mgr/categories/{id}/products/multiple
     *
     * @param array $params
     * @return array Response
     */
    public function multiple(array $params = []): array
    {
        $method = $params['method'] ?? '';
        $ids = $params['ids'] ?? [];

        if (empty($method)) {
            return Response::error('Method is required', 400)->getData();
        }

        if (empty($ids) || !is_array($ids)) {
            return Response::error('Product IDs array is required', 400)->getData();
        }

        // Sanitize IDs
        $ids = array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        });

        if (empty($ids)) {
            return Response::error('No valid product IDs provided', 400)->getData();
        }

        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $product = $this->modx->getObject(msProduct::class, $id);

            if (!$product) {
                $failed++;
                continue;
            }

            $result = false;

            switch ($method) {
                case 'publish':
                    $product->set('published', 1);
                    $product->set('publishedon', time());
                    $product->set('publishedby', $this->modx->user->get('id'));
                    $result = $product->save();
                    break;

                case 'unpublish':
                    $product->set('published', 0);
                    $product->set('publishedon', 0);
                    $product->set('publishedby', 0);
                    $result = $product->save();
                    break;

                case 'delete':
                    $product->set('deleted', 1);
                    $product->set('deletedon', time());
                    $product->set('deletedby', $this->modx->user->get('id'));
                    $result = $product->save();
                    break;

                case 'undelete':
                    $product->set('deleted', 0);
                    $product->set('deletedon', 0);
                    $product->set('deletedby', 0);
                    $result = $product->save();
                    break;

                case 'show':
                    $product->set('hidemenu', 0);
                    $result = $product->save();
                    break;

                case 'hide':
                    $product->set('hidemenu', 1);
                    $result = $product->save();
                    break;

                default:
                    $this->modx->log(modX::LOG_LEVEL_WARN, "[CategoryProductsController] Unknown method: {$method}");
            }

            if ($result) {
                $success++;
            } else {
                $failed++;
            }
        }

        if ($success === 0) {
            return Response::error('No products were updated', 500)->getData();
        }

        return Response::success([
            'success' => $success,
            'failed' => $failed
        ], "{$success} products updated")->getData();
    }

    /**
     * Bulk delete products
     * DELETE /api/mgr/categories/{id}/products/bulk
     *
     * @param array $params
     * @return array Response
     */
    public function bulkDelete(array $params = []): array
    {
        $params['method'] = 'delete';
        return $this->multiple($params);
    }

    /**
     * Toggle product publish status
     * POST /api/mgr/categories/{id}/products/{productId}/publish
     *
     * @param array $params
     * @return array Response
     */
    public function publish(array $params = []): array
    {
        $productId = (int)($params['productId'] ?? 0);
        $published = isset($params['published']) ? (int)$params['published'] : null;

        if (!$productId) {
            return Response::error('Product ID is required', 400)->getData();
        }

        $product = $this->modx->getObject(msProduct::class, $productId);

        if (!$product) {
            return Response::error('Product not found', 404)->getData();
        }

        // If published param not provided, toggle current state
        if ($published === null) {
            $published = $product->get('published') ? 0 : 1;
        }

        $product->set('published', $published);
        if ($published) {
            $product->set('publishedon', time());
            $product->set('publishedby', $this->modx->user->get('id'));
        } else {
            $product->set('publishedon', 0);
            $product->set('publishedby', 0);
        }

        if (!$product->save()) {
            return Response::error('Failed to update product', 500)->getData();
        }

        return Response::success([
            'id' => $productId,
            'published' => $published
        ], $published ? 'Product published' : 'Product unpublished')->getData();
    }

    /**
     * Map sort field to SQL expression (supports option fields)
     *
     * For option fields uses GROUP_CONCAT to comply with MySQL ONLY_FULL_GROUP_BY.
     *
     * @param string $sortBy
     * @param array $optionFields
     * @return string
     */
    protected function mapSortField(string $sortBy, array $optionFields): string
    {
        foreach ($optionFields as $opt) {
            if ($opt['fieldName'] === $sortBy) {
                return "GROUP_CONCAT(DISTINCT `{$opt['alias']}`.value)";
            }
        }
        $productFields = ['id', 'pagetitle', 'menuindex', 'published', 'createdon', 'editedon'];
        if (in_array($sortBy, $productFields)) {
            return "msProduct.{$sortBy}";
        }
        $dataFields = ['article', 'price', 'old_price', 'weight', 'vendor_id', 'made_in'];
        if (in_array($sortBy, $dataFields)) {
            return "Data.{$sortBy}";
        }
        return "msProduct.{$sortBy}";
    }

    /**
     * Format product row for API response
     *
     * @param array $row Raw row from query (includes joined option values)
     * @param bool $nested
     * @param array $optionFieldNames Allowed option field names (prevents leaking internal xPDO/MySQL columns)
     * @return array
     */
    protected function formatProduct(array $row, bool $nested = false, array $optionFieldNames = []): array
    {
        $id = (int)$row['id'];
        $data = [
            'id' => $id,
            'pagetitle' => $row['pagetitle'] ?? '',
            'longtitle' => $row['longtitle'] ?? '',
            'alias' => $row['alias'] ?? '',
            'parent' => (int)($row['parent'] ?? 0),
            'menuindex' => (int)($row['menuindex'] ?? 0),
            'published' => (bool)($row['published'] ?? false),
            'deleted' => (bool)($row['deleted'] ?? false),
            'hidemenu' => (bool)($row['hidemenu'] ?? false),
            'createdon' => $row['createdon'] ?? null,
            'editedon' => $row['editedon'] ?? null,
            'article' => $row['article'] ?? '',
            'price' => (float)($row['price'] ?? 0),
            'old_price' => (float)($row['old_price'] ?? 0),
            'weight' => (float)($row['weight'] ?? 0),
            'image' => $row['image'] ?? '',
            'thumb' => $row['thumb'] ?? '',
            'vendor_id' => (int)($row['vendor_id'] ?? 0),
            'made_in' => $row['made_in'] ?? '',
            'new' => (bool)($row['new'] ?? false),
            'popular' => (bool)($row['popular'] ?? false),
            'favorite' => (bool)($row['favorite'] ?? false),
            'preview_url' => $this->modx->makeUrl($id, '', '', 'full'),
        ];

        $allowedOptionFields = array_flip($optionFieldNames);
        foreach ($row as $key => $value) {
            if (!array_key_exists($key, $data) && isset($allowedOptionFields[$key])) {
                $data[$key] = $value;
            }
        }

        if ($nested && ($row['parent'] ?? 0) != 0) {
            $parent = $this->modx->getObject(msCategory::class, (int)$row['parent']);
            if ($parent) {
                $data['category_name'] = $parent->get('pagetitle');
            }
        }

        return $data;
    }

    /**
     * Build base product list query with JOINs and filters (no select/sort/limit)
     *
     * @param int $categoryId
     * @param array $params
     * @param bool $nested
     * @param array $optionFields
     * @return \xPDO\Om\xPDOQuery
     */
    protected function buildProductListQuery(int $categoryId, array $params, bool $nested, array $optionFields): \xPDO\Om\xPDOQuery
    {
        $query = trim($params['query'] ?? '');
        $c = $this->modx->newQuery(msProduct::class);
        $c->innerJoin(msProductData::class, 'Data', 'msProduct.id = Data.id');

        foreach ($optionFields as $opt) {
            $alias = $opt['alias'];
            $key = $opt['key'];
            $c->leftJoin(
                msProductOption::class,
                $alias,
                "`{$alias}`.product_id = msProduct.id AND `{$alias}`.key = '{$key}'"
            );
        }

        $c->where(['msProduct.class_key' => msProduct::class]);

        if ($nested) {
            $categoryIds = $this->getChildCategories($categoryId);
            $categoryIds[] = $categoryId;
            $c->where(['msProduct.parent:IN' => $categoryIds]);
        } else {
            $c->where(['msProduct.parent' => $categoryId]);
        }

        if (!empty($query)) {
            $c->where([
                'msProduct.pagetitle:LIKE' => "%{$query}%",
                'OR:Data.article:LIKE' => "%{$query}%",
            ]);
        }

        $productBooleanFields = ['published', 'deleted', 'hidemenu', 'isfolder'];
        foreach ($productBooleanFields as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $c->where(["msProduct.{$field}" => (int)$params[$field]]);
            }
        }

        $dataBooleanFields = ['new', 'popular', 'favorite'];
        foreach ($dataBooleanFields as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $c->where(["Data.{$field}" => (int)$params[$field]]);
            }
        }

        $productTextFields = ['pagetitle', 'longtitle', 'alias', 'description', 'introtext', 'content'];
        foreach ($productTextFields as $field) {
            if (!empty($params[$field])) {
                $c->where(["msProduct.{$field}:LIKE" => "%{$params[$field]}%"]);
            }
        }

        $dataTextFields = ['article', 'made_in'];
        foreach ($dataTextFields as $field) {
            if (!empty($params[$field])) {
                $c->where(["Data.{$field}:LIKE" => "%{$params[$field]}%"]);
            }
        }

        $dataNumericFields = ['price', 'old_price', 'weight', 'vendor_id'];
        foreach ($dataNumericFields as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $c->where(["Data.{$field}" => $params[$field]]);
            }
        }

        foreach ($optionFields as $opt) {
            $paramKey = 'filter_' . $opt['fieldName'];
            if (isset($params[$paramKey]) && $params[$paramKey] !== '') {
                $c->where(["`{$opt['alias']}`.value:LIKE" => "%{$params[$paramKey]}%"]);
            }
        }

        if (!isset($params['deleted']) || $params['deleted'] === '') {
            $c->where(['msProduct.deleted' => 0]);
        }

        return $c;
    }

    /**
     * Get all child category IDs recursively
     *
     * @param int $parentId
     * @return array
     */
    protected function getChildCategories(int $parentId): array
    {
        $ids = [];

        $children = $this->modx->getIterator(msCategory::class, [
            'parent' => $parentId,
            'deleted' => 0,
            'class_key' => msCategory::class,
        ]);

        foreach ($children as $child) {
            $childId = $child->get('id');
            $ids[] = $childId;
            $ids = array_merge($ids, $this->getChildCategories($childId));
        }

        return $ids;
    }

    /**
     * Get default filters configuration
     *
     * @return array
     */
    protected function getDefaultFilters(): array
    {
        return [
            'query' => [
                'type' => 'text',
                'label' => 'search',
                'placeholder' => 'search_placeholder',
                'width' => '250px',
                'position' => 10,
            ],
            'published' => [
                'type' => 'select',
                'label' => 'published',
                'placeholder' => 'all',
                'options' => [
                    ['label' => 'Да', 'value' => 1],
                    ['label' => 'Нет', 'value' => 0],
                ],
                'width' => '120px',
                'position' => 20,
            ],
        ];
    }
}
