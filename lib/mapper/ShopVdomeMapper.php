<?php

    require_once "base\Parser.php";
    require_once "base\interfaces\IMapper.php";

    class ShopVdomeMapper extends Parser implements IMapper
    {
        const
            MAP_FILE_NAME = 'map',
            MAP_FILE_EXT = '.json';

        private $_maps = array();
        private $_mapsCount = 0;

        private $_config = array(
            'baseUrl'    => 'https://www.teremonline.ru',
            'catalogUrl' => 'https://www.teremonline.ru/catalog/',

            'stopImg' => 'photo-empty',

            'categoryItems'  => '.sc-items .sc-itm',
            'categoryUrl'    => 'a.sc-i-top', // href
            'categoryName'   => 'span.sc-i-hdr', // innertext
            'categoryImg'    => '.sci-img img', // src
            'checkChilds'    => 'span.sc-i-text a',
            'categoryChilds' => '.scfr-new-items a.scfr-ni', // href
            'childName'      => '.scfr-ni-descr span.scfr-nd-1', // innertext
            'childImg'       => '.scfr-ni-img img', // src

            'pagination' => '.pagination',
            'pages'      => '.scfr-filters .pagination ul li a', // innertext of last - 1 element is count pages

            'productItems'      => '.scfr-items .sar-itm',
            'productUrl'        => 'a.sar-content', // href
            'productSku'        => 'span.sar-artic',// innertext (Арт: {art}&nbsp;&nbsp;)
            'productImg'        => 'img[itemprop=image]', // src
            'productInStock'    => '.sar-stock.green',
            'productOutOfStock' => '.sar-stock',

            'productContainer' => '.s-catalog-detail',
            'productPrice' => 'span.newPriceDetail-js', // innertext
            'productDescription'=> '.sc-element-descr .sced-p', //innerhtml
            'productTitle' => 'h1.sc-te-hdr',
            'productFeatures' => '#character .sced-list .sced-l-itm', // features list for foreach
            'featureName' => 'span.sced-l-descr-1', // innertext
            'featureValue' => 'span.sced-l-descr-2', // innertext
            'productDocs' => '#doc .serti-block', // sertificates block
            'docHeader' => 'span.sced-bg-hdr', // headers
            'docItemSet' => '.sced-img-wrap', // item set
            'doc' => 'a', // sertificates
            'productImageContainer' => '.swiper-container',
            'productImages' => 'span.svs-wrap-img img' //src
        );

        /** ----------------------- INTERFACE ----------------------- **/
        /** ----------------------- --------- ----------------------- **/

        public function createMaps($path)
        {
            $html = $this->getHtml($this->_config['catalogUrl']);

            if (!$html) {
                $this->_log("Catalog URL is not reachable now. Map not drawn. Parsing is impossible. :(");
            }

            if (!empty($html->find($this->_config['categoryItems']))) {

                $categoryItems = $html->find($this->_config['categoryItems']);

                foreach ($categoryItems as $categoryItem) {
                    $map = array();
                    $item = array();
                    $item['full_url'] = $categoryItem->find($this->_config['categoryUrl'])[0]->href;
                    $item['url'] = $this->_getCategoryUrl($item['full_url']);
                    $item['parse_url'] = $this->_config['baseUrl'] . $item['full_url'];
                    $item['name'] = $categoryItem->find($this->_config['categoryName'])[0]->innertext;
                    $item['img'] = $this->_getImgUrl($categoryItem->find($this->_config['categoryImg'])[0]->src);
                    if (!empty($categoryItem->find($this->_config['checkChilds']))) {
                        $item['childs'] = $this->_getRootChilds($item['parse_url']);
                    }
                    array_push($map,$item);

                    if ($map) {
                        $mapFilePath = $path.self::MAP_FILE_NAME."_".++$this->_mapsCount.self::MAP_FILE_EXT;
                        if (waFiles::write($mapFilePath, json_encode($map,1))) {
                            array_push($this->_maps,$mapFilePath);
                            $this->_log($mapFilePath . " map file created.");
                        }
                    }
                }
            } else {
                $this->_log("HTML DOM is changed. Parsing is impossible. :(");
            }

            if ($this->_mapsCount > 0) {
                return true;
            }

            return false;
        }

        public function getMaps()
        {
            return $this->_maps;
        }

        public function getMapsCount()
        {
            return $this->_mapsCount;
        }

        public function getProductData($url)
        {
            $productData = array();

            $html = $this->getHtml($url);

            if (!$html) {
                $this->_log("{$url}: Product URL is not reachable now. Product will not be received. :(");
                return false;
            }

            if ($html->find($this->_config['productContainer'])) {

                if ($html->find($this->_config['productDescription'])) {
                    $productData['description'] = $html->find($this->_config['productDescription'])[0]->innerhtml;
                }

                if ($html->find($this->_config['productPrice'])) {
                    $productData['price'] = preg_replace("#[^0-9\.]#","",$html->find($this->_config['productPrice'])[0]->innertext);
                }

                if ($html->find($this->_config['productTitle'])) {
                    $productData['name'] = preg_replace("#&quot;#","\"",trim($html->find($this->_config['productTitle'])[0]->innertext));
                }

                if ($html->find($this->_config['productFeatures'])) {
                    $features = array();

                    foreach ($html->find($this->_config['productFeatures']) as $featureContainer) {
                        $featureName = $featureContainer->find($this->_config['featureName'])[0]->innertext;

                        if ($featureName == "Бренд") {
                            $featureValue = $featureContainer->find($this->_config['featureValue'] . " a")[0]->innertext;
                            $features['Производитель'] = trim($featureValue);
                        } else {
                            if ($featureName == "Подгруппа") continue;
                            $featureValue = $featureContainer->find($this->_config['featureValue'])[0]->innertext;
                            $features[$featureName] = trim($featureValue);
                        }
                    }

                    $productData['features'] = $features;
                }

                $docsContainer = $html->find($this->_config['productDocs']);
                if ($docsContainer && !empty($docsContainer)) {
                    foreach ($html->find($this->_config['productDocs']) as $container) {
                        $docs = array('withHeaders' => true, 'data' => array());

                        $headers = $container->find($this->_config['docHeader']);
                        $docItemSets = $container->find($this->_config['docItemSet']);

                        if (count($headers) == count($docItemSets)) {
                            for ($i = 0; $i <= count($headers) - 1; $i++) {
                                $header = trim($headers[$i]->innertext);
                                $docs['data'][$header] = array();
                                foreach ($docItemSets[$i]->find($this->_config['doc']) as $a) {
                                    $doc = array(
                                        'image' => $this->_getDocImage($a->attr['style']),
                                        'file' => $this->_config['baseUrl'] . preg_replace("#\s#", "%20",trim($a->href))
                                    );
                                    if (is_array($doc)) {
                                        array_push($docs['data'][$header],$doc);
                                    }
                                }
                            }
                        } else {
                            $docs['withHeaders'] = false;
                            foreach ($docItemSets as $itemSet) {
                                foreach ($itemSet->find($this->_config['doc']) as $a) {
                                    $doc = array(
                                        'image' => $this->_getDocImage($a->attr['style']),
                                        'file' => $this->_config['baseUrl'] . preg_replace("#\s#", "%20",trim($a->href))
                                    );
                                    array_push($docs['data'],$doc);
                                }
                            }
                        }

                        if ($docs) {
                            $productData['docs'] = $docs;
                        }
                    }
                }

                if ($imageContainer = $html->find($this->_config['productImageContainer'])) {
                    foreach ($html->find($this->_config['productImageContainer']) as $container) {
                        foreach ($container->find($this->_config['productImages']) as $img) {
                            $productData['images'][] = $this->_getImgUrl($img->src);
                        }
                    }
                }

            } else {
                $this->_log("HTML DOM is changed. Parsing is impossible.. :(");
            }

            return $productData;
        }

        public function getConfigField($field)
        {
            return !empty($this->_config) && array_key_exists($field,$this->_config) ? $this->_config[$field] : null;
        }

        /** ----------------------- CUSTOM METHODS ----------------------- **/
        /** ----------------------- ------ ------- ----------------------- **/

        protected function _getRootChilds($url)
        {
            $rootChilds = array();

            $html = $this->getHtml($url);

            if (!$html) {
                $this->_log("{$url}: Category URL is not reachable now. Map for this category not drawn. :(");
            }

            if (!empty($html->find($this->_config['categoryItems']))) {

                $catItems = $html->find($this->_config['categoryItems']);

                foreach ($catItems as $categoryItem) {
                    $item = array();
                    $item['full_url'] = $categoryItem->find($this->_config['categoryUrl'])[0]->href;
                    $item['url'] = $this->_getCategoryUrl($item['full_url']);
                    $item['parse_url'] = $this->_config['baseUrl'] . $item['full_url'];
                    $item['name'] = $categoryItem->find($this->_config['categoryName'])[0]->innertext;
                    if ($categoryItem->find($this->_config['categoryImg'],0)) {
                        $item['img'] = $this->_getImgUrl($categoryItem->find($this->_config['categoryImg'],0)->src);
                    }
                    if (!empty($categoryItem->find($this->_config['checkChilds']))) {
                        $rootProducts = $this->_getProducts($item['parse_url']);
                        $childsArray = $this->_getChilds($item['parse_url'],$rootProducts);
                        if (isset($childsArray['childs'])) {
                            $item['childs'] = $childsArray['childs'];
                        }
                        if (isset($childsArray['products'])) {
                            $this->_log(count($childsArray['products'])." is unlinked to childs and belongs to root category {$item['parse_url']}.");
                            $item['products'] = $childsArray['products'];
                        }
                    } else {
                        $item['products'] = $this->_getProducts($item['parse_url']);
                    }
                    array_push($rootChilds,$item);
                    $this->_log("We get {$item['parse_url']} category.");
                    break; // TODO remove this before production.
                }
            } else {
                $this->_log("HTML DOM or catalog structure is changed. Parsing is impossible. :(");
            }

            return $rootChilds;
        }

        protected function _getChilds($url,$rootProducts)
        {
            $childs = array();

            $childHtml = $this->getHtml($url);

            if (!$childHtml) {
                $this->_log("{$url}: Category URL is not reachable now. Map for this category not drawn. :(");
            }

            if (!empty($childHtml->find($this->_config['categoryChilds']))) {

                $categoryChilds = $childHtml->find($this->_config['categoryChilds']);

                foreach ($categoryChilds as $categoryChild) {
                    $child = array();
                    $child['full_url'] = $categoryChild->href;
                    $child['url'] = $this->_getCategoryUrl($child['full_url']);
                    $child['parse_url'] = $this->_config['baseUrl'] . $child['full_url'];
                    $child['name'] = $categoryChild->find($this->_config['childName'])[0]->innertext;
                    $child['img'] = $this->_getImgUrl($categoryChild->find($this->_config['childImg'])[0]->src);
                    $child['products'] = $this->_getProducts($child['parse_url']);

                    foreach ($child['products'] as $product) {
                        foreach ($rootProducts as $key => $rootProduct) {
                            if ($rootProduct['sku'] == $product['sku']) {
                                unset($rootProducts[$key]);
                            }
                        }
                    }

                    array_push($childs,$child);
                }
            } else {
                $this->_log("HTML DOM or catalog structure is changed. Parsing is impossible. :(");
            }

            return array( 'childs' => $childs, 'products' => $rootProducts);
        }

        protected function _getProducts($url)
        {
            $products = array();

            $html = $this->getHtml($url);

            if (!$html) {
                $this->_log("{$url}: Category URL is not reachable now. Products for this category will not be received. :(");
            }

            if ($html->find($this->_config['pagination'])) {
                $index = count($html->find($this->_config['pages'])) - 2;
                $lastPageNum = $html->find($this->_config['pages'])[$index]->innertext;
                if ($lastPageNum) {
                    for ($i = 1; $i <= $lastPageNum; $i++) {
                        $pageUrl = $this->_getPaginationUrl($url,$i);
                        $products = array_merge($products,$this->_getPageProducts($pageUrl));
                    }
                }
            } else {
                $products = array_merge($products,$this->_getPageProducts($url));
            }

            $this->_log("Category {$url} has ".count($products)." products.");

            return $products;
        }

        protected function _getPageProducts($url)
        {
            $pageProducts = array();

            $html = $this->getHtml($url);

            if (!$html) {
                $this->_log("{$url}: Category URL is not reachable now. Products for this category will not be received. :(");
            }

            if ($html->find($this->_config['productItems'])) {

                $productItems = $html->find($this->_config['productItems']);

                foreach ($productItems as $productItem) {
                    $item = array();
                    $item['url'] = $this->_getProductUrl($productItem->find($this->_config['productUrl'])[0]->href);
                    $item['parse_url'] = $this->_config['baseUrl'] . $productItem->find($this->_config['productUrl'])[0]->href;
                    $item['sku'] = $this->_getSku($productItem->find($this->_config['productSku'])[0]->innertext);
                    $item['img'] = $productItem->find($this->_config['productImg'], 0)->src;
                    $item['in_stock'] = $productItem->find($this->_config['productInStock']) ? true : false;

                    array_push($pageProducts,$item);
                }

                $html->clear();
            } else {
                $this->_log("{$url}: Products from this page are not received. :(");
            }

            return $pageProducts;
        }

        protected function _getImgUrl($src)
        {
            return $this->_config['baseUrl'] . $src;
        } // parsing

        protected function _getCategoryUrl($fullUrl)
        {
            $url = trim($fullUrl,"/");
            $explode = explode("/",$url);
            $return = preg_replace("#[0-9_]#",'', trim($explode[count($explode) - 1]));
            return $return;
        } // parsing

        protected function _getProductUrl($fullUrl)
        {
            $url = trim($fullUrl,"/");
            $explode = explode("/",$url);
            return trim($explode[count($explode) - 1]);
        } // get page products

        protected function _getSku($skuString)
        {
            $sku = preg_replace("#Арт:\s#","",$skuString);
            $sku = preg_replace("#\s#","",$sku);
            $sku = preg_replace("#&nbsp;#","",$sku);
            return preg_replace("#\"#","",trim($sku));
        } // get page products

        protected function _getPaginationUrl($url, $page)
        {
            return $url . "?PAGEN_3={$page}";
        } // get products

        protected function _getDocImage($style)
        {
            $url = preg_replace("#background-image: url\(#","", $style);
            $url = preg_replace("#\);#","", $url);
            return $this->_config['baseUrl'] . preg_replace("#\s#", "%20",trim($url));
        } // get product data

    }