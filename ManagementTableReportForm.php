<?php

class ManagementTableReportForm extends CFormModel
	implements InterfaceReportForm
{
    public $dateStart;
    public $dateEnd;
   
    private $dayborders = [];
    private $saleBeforeP = [];
    private $saleIntoP = [];

    public $leads_per_day = [];				//	сделки в день
    public $leaditems_per_day = [];			//	лиды в день
    public $mpp_calls_per_day = [];			//	звонки мпп в день
    public $mpp_meets_per_day = [];			//	встречи мпп в день
    
    public $leads_inwork_day = [];			//	сделки в работе / день
    public $leads_sum_inwork_day = [];		//	сумма сделоки в работе / день
    
    public $payments_per_day = [];			//	оплаты в день
    
    public $products_rep_day = [];			//	продления продаж в день
    public $products_new_day = [];			//	новые продажи в день
    public $products_new_site_day = [];		//	новые продажи-домен/сайт/хостинг в день
    /**
     * Declares the validation rules.
     */
    public function rules()
    {
        return array(// name, email, subject and body are required
        	array( 'dateStart, dateEnd', 'safe'),	
        );
    }
    
    public function checkAccess( $actions )
    {
    	return true;
    }

    /**
     * Declares customized attribute labels.
     * If not declared here, an attribute would have a label that is
     * the same as its name with the first letter in upper case.
     */
    public function attributeLabels()
    {
        return array(
    
        );
    }

    public function setDefaults()
    {
        $this->dateEnd = date('Y-m-d');
        $this->dateStart = date('Y-m-d', time()-3600*24*50);
        
    }
    
        
    protected function prepareReport()
    {
    	
    }
    
    
    public function prepareParams( &$input)
    {
    	if( isset( $input['dateStart'] ))
    	{
    		$input['dateStart'] = Utility::formatDateIn($input['dateStart']);
    	}
    	if( isset( $input['dateEnd'] ))
    	{
    		$input['dateEnd'] = Utility::formatDateIn($input['dateEnd']);
    	}
    }
       
    
    public function defaultReport()
    {
    	$this->performReport();	 
    }


    public function performReport()
    {
    	
    	$this->dayborders = $this->fillDayborders();
    	
    	$this->fill_leaditems_per_day();
    	$this->fill_mpp_calls_per_day();
    	$this->fill_mpp_meets_per_day();
    	$this->fill_leads_per_day();
    	
    	$this->fill_leads_inwork_day();
    	$this->fill_sales_per_day();
    	$this->fill_payments_per_day();
    }

	public function getDays()
	{
		return $this->dayborders;
	}
	/*
	 * границы(старты) учетных дней в таймстампах
	 */
    private function fillDayborders()
    {
    	$R = [];
    	
    	$b = strtotime($this->dateStart);
    	$e = strtotime($this->dateEnd);
    	
    	if( $b > $e ) return [];
    	
    	do{
    		$R[] = $b;
    		$b += 24*3600;
    	} while( $b <= $e);
    	
    	return $R;
    }
    
    
    private function fill_leaditems_per_day()
    {
    	$criteria = new CDbCriteria();
    	$criteria->addCondition( 't.add_time >= :timeStart');
    	$criteria->addCondition( 't.add_time <= :timeEnd');
    	$criteria->addCondition('t.active > 0');
    	$criteria->params = [
    		'timeStart' =>  $this->dateStart . " 0:0:0",
    		'timeEnd' =>  $this->dateEnd . " 23:59:59",
    	];
    	
    	$dataProvider = new CActiveDataProvider('LeadItem', array(
    			'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 1000);
    	foreach( $iterator as $item)
    	{
    		$this->push_to_array($this->leaditems_per_day, strtotime($item->add_time));		
    	}
    }
   
    
    private function fill_leads_per_day()
    {
    	$criteria = new CDbCriteria();
    	$criteria->addCondition( 't.add_date >= :timeStart');
    	$criteria->addCondition( 't.add_date <= :timeEnd');
    	$criteria->addCondition('t.active > 0');
    	$criteria->params = [
    			'timeStart' =>  $this->dateStart . " 0:0:0",
    			'timeEnd' =>  $this->dateEnd . " 23:59:59",
    	];
    	 
    	$dataProvider = new CActiveDataProvider('Lead', array(
    			'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 1000);
    	foreach( $iterator as $item)
    	{
    		$this->push_to_array($this->leads_per_day, strtotime($item->add_date));
    	}
    }
    
    
    private function fill_payments_per_day()
    {
    	$criteria = new CDbCriteria();
    	$criteria->addCondition( 't.payment_date >= :timeStart');
    	$criteria->addCondition( 't.payment_date <= :timeEnd');
    	$criteria->addCondition('t.active > 0');
    	$criteria->params = [
    			'timeStart' =>  $this->dateStart . " 0:0:0",
    			'timeEnd' =>  $this->dateEnd . " 23:59:59",
    	];
    
    	$dataProvider = new CActiveDataProvider('Payment', array(
    			'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 1000);
    	foreach( $iterator as $item)
    	{
    		$this->push_to_array($this->payments_per_day, strtotime($item->payment_date), $item->summ);
    	}
    }
    
    
    private function fill_sales_per_day()
    {
    	$criteria = new CDbCriteria();
    	$criteria->addCondition( 'DPL.date >= :timeStart');
    	$criteria->addCondition( 'DPL.date <= :timeEnd');
    	$criteria->addCondition('t.active > 0');
    	$criteria->addCondition('t.W_lock = "Y"');
    	$criteria->params = [
    			'timeStart' =>  $this->dateStart . " 0:0:0",
    			'timeEnd' =>  $this->dateEnd . " 23:59:59",
    	];
    	$criteria->join = // 2 раза лог проведений, чтобы словить первое
    	' INNER JOIN	{{document_proove_log}} DPL 
    				ON t.ID = DPL.FK_doc_ID AND DPL.FK_action = 1 AND DPL.FK_doc_type = 4
    	  LEFT JOIN		{{document_proove_log}} DPL_B 
    				ON t.ID = DPL_B.FK_doc_ID AND DPL_B.FK_action = 1 AND DPL_B.FK_doc_type = 4 AND DPL.ID > DPL_B.ID
    	';
    	$criteria->addCondition( 'ISNULL( DPL_B.ID)');
    	
    	
    	// Все продажи, подтвержденные в период
    	$sales = Sale::model()->findAll($criteria);
    	if( count( $sales ) == 0 ) return;
    	
    	// Границы периода продаж? (контрольные месяцы)
    	$sale = reset ( $sales);
    	$minM = $sale->FK_control_month;
    	$maxM = $sale->FK_control_month;	
    	foreach ($sales as $sale)
    	{
    		if( $sale->FK_control_month < $minM)
    				$minM = $sale->FK_control_month;
    		if( $sale->FK_control_month > $maxM)
    				$maxM = $sale->FK_control_month;
    	}
    		
    	// Заполним таблицы для контроля первости продаж
    	$this->fillFirstTimesTable( $minM, $maxM);
    	
    	// Распределим продажи/продукты по новости/старости/типам
    	foreach ($sales as $sale)
    	{
    		$firstProove = DocumentProoveLog::model()->findByAttributes([
    			'FK_doc_type'	=>	4,
 				'FK_doc_ID'		=>	$sale->ID
    		]);   		
    		$T = strtotime( $firstProove->date);
    		foreach( $sale->products as $P)
    		{
    			if( $P->FK_sale_product_kind == 10)
    				$this->push_to_array($this->products_new_site_day, $T, $P->calcVD());
    			else
    			{
    				// новая продажа?
    				if ($this->isFirstSaleProduct($P))
    					$this->push_to_array($this->products_new_day, $T, $P->calcVD());
    				// не новая.
    				else 
    					$this->push_to_array($this->products_rep_day, $T, $P->calcVD());
    			}
    		}	
    	}
    }
    
    
    public function isFirstSaleProduct( SaleProduct $P )
    {
    	$criteria = $P->FK_sale_product_kind * 1000 + $P->elaboration->FK_feature1;
    	$company = $P->sale->FK_company;
    	$month = $P->sale->FK_control_month;
    
    	// есть в периоде и раньше
    	if (
    		isset( $this->saleIntoP[ $criteria ][ $company ]) &&
    		$this->saleIntoP[ $criteria ][ $company ] < $month
    	)
    	return false;
    
    	// это первый в периоде. как до периода?
    	if(
    		!isset( $this->saleBeforeP[ $criteria ][ $company ]) ||
    		($month - $this->saleBeforeP[ $criteria ][ $company ]) > 15
    	)
    	return true;
    
    	return false;
    }
    
    
    // 
    private function fillFirstTimesTable( $minM, $maxM )
    {
    	$DS = $this->getBaseStructureQuery(  $minM - 17, $minM - 1 );
    	$DS->andWhere( 'E.is_long = 1' )->group( 'G, C');
    
    
    	$DSin = $this->getBaseStructureQuery( $minM, $maxM );
    	$DSin->andWhere( 'E.is_long = 1' )->group( 'G, C');
    
    	//Последний раз ДО периода
    	$DS
    	->select(
    			'max( S.FK_control_month ) as M,
    			S.FK_company  AS C,
    			P.FK_sale_product_kind * 1000 + E.FK_feature1 AS G');
    	 
    
    	//Первый раз в периоде
    	$DSin
    	->select(
    			'min( S.FK_control_month ) as M,
    			S.FK_company  AS C,
    			P.FK_sale_product_kind * 1000 + E.FK_feature1 AS G');
    
    	 
    		
    	$data = $DS->queryAll();
    	$R = [];
    
    	foreach ( $data as $line )
    	{
    		if ( !isset( $R[ $line['G'] ] ))
    			$R[ $line['G'] ] = [];
    			if ( !isset( $R[ $line['G'] ][ $line['C'] ] ))
    				$R[ $line['G'] ][ $line['C'] ] = $line[ 'M' ];
    	}
    	$this->saleBeforeP = $R;
    	 
    
    	$R = [];
    	$data2 = $DSin->queryAll();
    	foreach ( $data2 as $line )
    	{
    		if ( !isset( $R[ $line['G'] ] ))
    			$R[ $line['G'] ] = [];
    			if ( !isset( $R[ $line['G'] ][ $line['C'] ] ))
    				$R[ $line['G'] ][ $line['C'] ] = $line[ 'M' ];
    
    	}
    	$this->saleIntoP = $R;
    
    	 
    	 
    }
    
    
    private function getBaseStructureQuery( $start = 0, $end = 0)
    {
    	   	 
    	$DS = Yii::app()->db->createCommand();
    	$DS->from('{{sales_products}} P')
    	->join('{{sales}} S', 'P.FK_sale = S.ID')
    	->join('{{sales_products_kinds}} SK', 'P.FK_sale_product_kind = SK.ID')
    	->join('{{companies}} C', 'S.FK_company = C.ID')
    	->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
    	->leftJoin( '{{sales_summaries}} SMM', 'SMM.FK_sale = S.ID');
    	 
    	$DS->where( 'S.FK_control_month >= '.$start)
    	->andWhere('S.FK_control_month <= '.$end)
    	->andWhere('P.active > 0')
    	->andWhere('S.active > 0')
    	->andWhere('C.active > 0')
    	->andWhere('S.FK_sale_state != 3');
    	 
    	return $DS;
    }
    
    
    private function fill_leads_inwork_day()
    {
    	$criteria = new CDbCriteria();
    	$criteria->addCondition( 't.add_date <= :timeEnd');
    	$criteria->addCondition( 't.donedatetime >= :timeStart OR t.donedatetime < :farAlong');
    	$criteria->addCondition('t.active > 0');
    	$criteria->params = [
    			'timeStart' =>  $this->dateStart . " 0:0:0",
    			'timeEnd'  =>  $this->dateEnd . " 23:59:59",
    			'farAlong' =>  "1980-01-01 23:59:59",
    	];
    
    	$dataProvider = new CActiveDataProvider('Lead', array(
    			'criteria'=>$criteria,
    	));
    	
    	$iterator=new CDataProviderIterator($dataProvider, 1000);
    	
    	foreach( $iterator as $item)
    	{
    		foreach ( $this->dayborders as $key => $daystart)
    		{
    			$item_start = strtotime($item->add_date);
    			$item_end = (strtotime($item->donedatetime)>10000) ? strtotime($item->donedatetime) : 0;
    			
    			if( $item_start >= $daystart + 24*3600 ) continue;
    			if( $item_end > 0 && $item_end < $daystart ) continue;
    			
    			if(!isset( $this->leads_inwork_day[$key]))	$this->leads_inwork_day[$key] = 0;
    			$this->leads_inwork_day[$key]++;
    			
    			if(!isset( $this->leads_sum_inwork_day[$key]))	$this->leads_sum_inwork_day[$key] = 0;
    			$this->leads_sum_inwork_day[$key] += $item->total;
    		}		
    	}
    }
    
    
    private function fill_mpp_calls_per_day()
    {
    	Yii::import('ext.telphinapi.models.*', true);
    	
    	// белый список экстеншенов = внутренних номеров
    	$mpps = Yii::app()->accDispatcher->getTriggerGroupList( AbstractAccessDispatcher::GROUP_SALEMGR ); 
    	$criteria = new CDbCriteria();
    	$criteria->addInCondition('FK_user', $mpps);
    	$extensions = TelphinExtension::model()->findAll($criteria);
    	$wList = [];
    	foreach ($extensions as $ext) $wList[] = $ext->name;
    	
    	
    	// сбор звонков
    	$criteria = new CDbCriteria();
    	$criteria->addInCondition('iniciator_number', $wList);
    	$criteria->addInCondition('call_number', $wList, 'OR');
    	    	
    	$criteria->addCondition( 't.create_time >= :timeStart');
    	$criteria->addCondition( 't.create_time <= :timeEnd');
    	$criteria->params[ 'timeStart' ]  =  $this->dateStart . " 0:0:0";
    	$criteria->params[ 'timeEnd' ] =  $this->dateEnd . " 23:59:59";
    	    	
    	$dataProvider = new CActiveDataProvider('TelphinCall', array(
    			'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 1000);
    	foreach( $iterator as $call)
    	{
    		// пропустим внутренние
    		if(
    			TelphinUtility::isLocalNumber($call->iniciator_number) &&
    			TelphinUtility::isLocalNumber($call->call_number)
    		) continue;
    		
    		
    		$this->push_to_array($this->mpp_calls_per_day, strtotime($call->create_time));	
    	}
    }
    
    
    private function fill_mpp_meets_per_day()
    {
    	    	 
    	// белый список экстеншенов = внутренних номеров
    	$mpps = Yii::app()->accDispatcher->getTriggerGroupList( AbstractAccessDispatcher::GROUP_SALEMGR );
    	    	 
    	// сбор задач
    	$criteria = new CDbCriteria();
    	
    	$criteria->with = [ 'executorLink'];
    	$criteria->addCondition( 't.FK_task_type = '. TaskType::TYPE_MEETING);
    	$criteria->addCondition( 't.close_time >= :timeStart');
    	$criteria->addCondition( 't.close_time <= :timeEnd');
    	$criteria->params[ 'timeStart' ]  =  $this->dateStart . " 0:0:0";
    	$criteria->params[ 'timeEnd' ] =  $this->dateEnd . " 23:59:59";
    	$criteria->addInCondition('executorLink.user_id', $mpps);
    	
    	$dataProvider = new CActiveDataProvider('Task', array(
    			'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 1000);
    	foreach( $iterator as $task)
    	{
    		// пропустим внутренние
    		$this->push_to_array($this->mpp_meets_per_day, strtotime($task->close_time));
    	}
    }
    
    private function push_to_array( &$array, $T, $val = 1)
    {
    	$key = 0;
    	while( $T > ($this->dayborders[ $key ] + 24*3600))
    	{
    		$key++;
    	}
    	 
    	if( !isset( $array[ $key ]))
    		$array[ $key ] = 0;
    	$array[ $key ] += $val;
    	
    }
    
    public function getValue( $item, $key)
    {

    	if( !isset( $this->$item) ) return 0;
		$L = $this->$item;
		
    	if(	 !isset( $L[ $key ])) return 0;
    	
    	return  $L[ $key ];
    }
    
    public function getValueSum( $item, $keyFrom, $keyTo)
    {
    
    	if( $keyFrom<0 ) $keyFrom = 0;
    	$S = 0;
    	
    	for ( $i = $keyFrom; $i <= $keyTo; $i++)
    	{
    		$S += $this->getValue($item, $i);
    	}
    	return  $S;
    }
    
    public function getValueMax( $item, $keyFrom, $keyTo)
    {
    
    	if( $keyFrom<0 ) $keyFrom = 0;
    	$S = 0;
    	 
    	for ( $i = $keyFrom; $i <= $keyTo; $i++)
    	{
    		if( $this->getValue($item, $i) > $S)
    			$S = $this->getValue($item, $i);
    	}
    	return  $S;
    }
}