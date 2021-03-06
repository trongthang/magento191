<?php
/**
 * Created by JetBrains PhpStorm.
 * User: prasad
 * Date: 9/9/13
 * Time: 12:01 PM
 * To change this template use File | Settings | File Templates.
 */
class Netstarter_CI_Helper_Review
{

    protected $_dumpHandle;
    protected $_feedRead;
    protected $_fileBasePath;

    private $_attributes;
    private $_connection;
    private $_entityTable;
    private $_entityTypeId;
    private $_resource;
    private $_attributesToImport;

    private $_ratingResource;
    private $_reviewResource;

    private $_optionsMap = array();
    private $_ratingId = 1;



    public function __construct()
    {
        $this->_fileBasePath = Mage::getBaseDir('var');


        $this->_entityTable   = Mage::getModel('customer/customer')->getResource()->getEntityTable();
        $this->_connection      = Mage::getSingleton('core/resource')->getConnection('write');
        $this->_resource       = Mage::getModel('customer/customer');
        $this->_ratingResource = Mage::getResourceModel('rating/rating_option');
        $this->_reviewResource = Mage::getResourceModel('review/review');

        $this->_rateOptionsLoad();
        $this->_entityTypeId = 1;
    }

    protected function _getFeedFilePath()
    {
        return $this->_fileBasePath.DS.'ci'.DS.'feed'.DS.'reviews.csv';
    }

    protected function _readFeedFile()
    {
        if (file_exists($this->_getFeedFilePath())){

            $this->_feedRead = fopen($this->_getFeedFilePath(), 'r');
        }
    }

    protected function _closeFileHandle()
    {

        if ($this->_dumpHandle !== null) {

            fclose($this->_dumpHandle);
        }

        if ($this->_feedRead !== null) {

            fclose($this->_feedRead);
        }
    }

    protected function _rateOptionsLoad()
    {
        $write = $this->_connection;
        $options = $write->fetchAll("SELECT * FROM  `rating_option` WHERE rating_id = {$this->_ratingId}");

        foreach($options as $option){
            $value = (int) $option['value'];
            $optionId = (int) $option['option_id'];
            $this->_optionsMap[$value] = $optionId;
        }
    }

    private function _prepareRow($line, $lineNumber)
    {
        $rowData = array('title' => null, 'code' => null, 'item_colour_ref' => null,'first_name' => null, 'last_name' => null, 'user_name' => null,
                        'email' => null, 'status' => null, 'date_created' => null,'rating' => null, 'review' => null);
        $bunch = explode(',' , $line);

        if(count($bunch) > 10){

            $rowData['title']  = trim($bunch[0]);
            $rowData['code'] = trim($bunch[1]);
            $rowData['item_colour_ref'] = trim($bunch[2]);
            $rowData['first_name'] = trim($bunch[3]);
            $rowData['last_name'] = trim($bunch[4]);
            $rowData['user_name'] = trim($bunch[5]);
            $rowData['email'] = trim($bunch[6]);
            $rowData['status'] = trim($bunch[7]);
            $rowData['date_created'] = trim($bunch[8]);
            $rowData['rating'] = trim($bunch[9]);
            $rowData['review'] = trim($bunch[10]);

            $extra = trim($bunch[11]);
            if(!empty($extra)){

                $rowData['review'] = $rowData['review'].$extra;
            }

            if(empty($rowData['review'])){

                mage::log($lineNumber, null, 'review_empty.log');
                return null;
            }

            return $rowData;
        }

        return null;
    }

    public function importReviews()
    {
        $this->_readFeedFile();
        echo "Reviews Import Started..... \n";
        if ($this->_feedRead !== null) {

            fgets($this->_feedRead);
            $dateCreated = date('Y-m-d h:i:s');
            $aggregatedProducts = array();

            $write = $this->_connection;

            $lineNumber = 1;

            while (!feof($this->_feedRead)) {
                ++$lineNumber;

                echo "Now in $lineNumber..... \n";
                $line = fgets($this->_feedRead);
                if(empty($line)) continue;

                if($rowData = $this->_prepareRow($line, $lineNumber)){

                    $entityId = $write->fetchOne("select entity_id from catalog_product_entity where sku= ?", $rowData['item_colour_ref']);


                    if ($entityId !== false){

                        try{

                            $write->beginTransaction();
                            $write->query("INSERT INTO review (created_at, entity_id, entity_pk_value, status_id)
                                            VALUES(:created_at, 1, :entity_pk_value, 1)",array('created_at' => $dateCreated,
                                                                                                'entity_pk_value'=>$entityId));

                            $reviewId = $write->lastInsertId();

                            $write->query("INSERT INTO review_detail (review_id, store_id, title, detail, nickname)
                                            VALUES(:review_id, 1, :title, :detail, :nickname)",
                                                    array('review_id' => $reviewId,
                                                        'title'=> $rowData['title'],
                                                        'detail'=> $rowData['review'],
                                                        'nickname'=> "{$rowData['first_name']} {$rowData['last_name']}"));

                            $write->query("INSERT INTO review_store (review_id, store_id)
                                            VALUES(:review_id, 0), (:review_id, 1), (:review_id, 2)", array('review_id' => $reviewId));

                            if($rowData['rating']){
                                $percent = 20*$rowData['rating'];

                                $write->query("INSERT INTO rating_option_vote (option_id, remote_ip, remote_ip_long, customer_id, entity_pk_value, rating_id, review_id, percent, value)
                                            VALUES(:option_id, '127.0.0.1', 2130706433, NULL, :entity_pk_value, :rating_id, :review_id, :percent, :value)",
                                    array('option_id' => $this->_optionsMap[$rowData['rating']], 'entity_pk_value' => $entityId, 'rating_id'=> $this->_ratingId, 'review_id'=> $reviewId, 'percent'=> $percent, 'value' => $rowData['rating']));

                                $aggregatedProducts[$entityId] = 1;

                            }

                            $write->commit();

                        }catch (Exception $e){

                            $write->rollback();

                            Mage::log("ERROR in line : $lineNumber: {$e->getMessage()}", null, 'review.log');

                            continue;
                        }
                    }else{

                        Mage::log("ERROR in line : $lineNumber: {$rowData['item_colour_ref']}", null, 'review_product.log');
                    }

                }else{
                    Mage::log("ERROR in line : $lineNumber: $line", null, 'review_proper.log');
                }
            }


            echo "Reviews Import Finished..... \n";

            echo "Starting Aggregation..... \n";
//            foreach($aggregatedProducts as $productId=>$val){
//
//                $this->_ratingResource->aggregateEntityByRatingId($this->_ratingId, $productId);
//
//                $obj = new Varien_Object();
//                $obj->setEntityPkValue($productId);
//                $obj->setEntityId(1);
//
//                $this->_reviewResource->aggregate($obj);
//
//                unset($obj);
//            }

            echo "Aggregation Finished..... \n";
        }

        $this->_closeFileHandle();

        echo "Import Finished..... \n";
    }
}