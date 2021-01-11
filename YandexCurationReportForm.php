<?php

class YandexCurationReportForm extends CFormModel
	implements InterfaceReportForm
{
    
	public $date_start;
	public $date_end;
	public $FK_company;
	public $company;
	
	public $datapack;
	public $companies = [];
	
	
    /**
     * Declares the validation rules.
     */
    public function rules()
    {
        return array(
        	['date_start, date_end, FK_company', 'safe'],

        );
    }
    
    
    public function prepareParams(&$arrData)
    {
    	
    	if( isset( $arrData[ 'date_start']))
    		$arrData[ 'date_start'] = date( "Y-m-d", strtotime( $arrData[ 'date_start']));
    	if( isset( $arrData[ 'date_end']))
    		$arrData[ 'date_end'] = date( "Y-m-d", strtotime( $arrData[ 'date_end']));
    	
    	
    }
    
    public function checkAccess( $actions )
    {
    	return true; //Yii::app()->accDispatcher->accessToClasses( [ 'ClientSegmentationReportForm' => 'R'] );
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
    	$curMonth = Month::getQueryID();
    	$this->date_start = Month::getInnerDate($curMonth, '2000-01-01');
    	$this->date_end = Month::getInnerDate($curMonth, '2100-01-01');
    }
    
    public function defaultReport()
    {
    	$this->performReport();
    }
    
        
    protected function prepareReport()
    {
    	$this->datapack = new CMap();
    	if( $this->FK_company)
    		$this->company = Company::model()->findByPk($this->FK_company);
    	else 
    		$this->company = null;
    }


    private function kindList()
    {
    	return [ 15];
    }
    
    
    
    private function enfillPeriodTotals()
    {
    	$DS = Yii::app()->db->createCommand()
    		->select( 'sum( stat.net_cost + stat.search_cost) as ttl, login.FK_company as cid')
    		->from( '{{companies_metric_yadirect_statdata}} stat' )
    		->join( '{{yadirect_lgns}} login', 'login.ID = stat.FK_directlgn')
    		->where( 'stat.statDate >= :startd' )
    		->andWhere( 'stat.statDate <= :endd' )
    		->group( 'cid');
    	if( $this->FK_company)
    		$DS->andWhere( 'login.FK_company = ' . $this->FK_company);
    	
    	$data = $DS->queryAll(1, [
    		'startd'	=> 	$this->date_start,
    		'endd'		=>	$this->date_end
    	]);
    	
		foreach ( $data as $line)
		{
			$item = new stdClass();
			$item->FK_company 	= $line['cid'];
			$item->periodStat 	= $line['ttl'];
			
			
			$this->datapack->add( $line['cid'], $item);
		}
    	
    	
    }
    
    
    
    private function enfillPredPeriodTotals()
    {
    	$DS = Yii::app()->db->createCommand()
    	->select( 'sum( stat.net_cost + stat.search_cost) as ttl, login.FK_company as cid')
    	->from( '{{companies_metric_yadirect_statdata}} stat' )
    	->join( '{{yadirect_lgns}} login', 'login.ID = stat.FK_directlgn')
    	
    	->where( 'stat.statDate < :startd' )
    	
    	->group( 'cid');
    	
    	    	
    	if( $this->FK_company)
    		$DS->andWhere( 'login.FK_company = ' . $this->FK_company);
    	else 
    		$DS->andWhere( 'login.FK_company IN (  ' . implode(', ', $this->datapack->keys). ')');
    	 
    	$data = $DS->queryAll(1, [
    			'startd'	=> 	$this->date_start,
    	]);
    	 
    	foreach ( $data as $line)
    	{
    		
    		if($item = $this->datapack->itemAt(  $line['cid'] ))
    		{
    			$item->beforeStat 	= $line['ttl'];  			
    			$this->datapack->add( $line['cid'], $item);
    		}
    	}
    	 
    	 
    }
    
    
    public function getCompanyProducts( $FK_company)
    {
    		
    		$mEnd = Month::getQueryID();
			if( date("d") < 7 )$mEnd--;
			
    		$criteria = new CDbCriteria();
    		$criteria->addInCondition('elaboration.ID', $this->kindList());
    		$criteria->compare('sale.FK_company', $FK_company);
    		$criteria->addCondition('sale.FK_sale_state != 3');
    		$criteria->addCondition('sale.active > 0');
    		$criteria->addCondition('t.active > 0');
    		$criteria->addCondition('sale.W_lock = "Y" OR
        		( sale.FK_control_month = ' . $mEnd . ' )');
    		$criteria->order = 'sale.FK_control_month, sale.ID ASC';
    		return SaleProduct::model()->with('sale', 'elaboration')->findAll( $criteria);
    		
    	
    	
    }
    
    
    private function updatePeriodTotals()
    {
    	$mStart = Month::getQueryID( date("m", strtotime( $this->date_start)), date("Y", strtotime( $this->date_start)));
    	$mEnd   = Month::getQueryID( date("m", strtotime( $this->date_end)), date("Y", strtotime( $this->date_end)));
    	
    	$DS = $this->getBaseSaleQuery();
    	$DS->selectDistinct( "C.ID")
    		->where("E.ID IN (" . implode(', ', $this->kindList()). ")")
    		->andWhere( 'S.FK_control_month >= :mStart')
    		->andWhere( 'S.FK_control_month <= :mEnd');
    	if( $this->FK_company)
    		$DS->andWhere( 'S.FK_company = ' . $this->FK_company);
    	   	 
    	$data = $DS->queryColumn( [
    		'mStart'	=>	$mStart,
    		'mEnd'		=>	$mEnd
    	]);
    	
    	foreach ( $data as $FK_company)
    	{
    		if( $this->datapack->contains( $FK_company ) )
    		{
    			//$item = $this->datapack->itemAt($FK_company); 
    		}
    		else 
    		{
    			$item = new stdClass();
    			$item->FK_company 	= $FK_company;
    			$item->periodStat 	= 0;
    			$this->datapack->add( $FK_company, $item);
    		}
    		
    	}

    }
    
    
    
    
    public function performReport()
    {
    	$this->prepareReport();
 			      
    	
    	// 1. В периоде. 
    	// Тоталы по логинам - статистика
    	$this->enfillPeriodTotals();
    	
    	// 1.1 В периоде. 
    	// Добить список компаний по продажам!
    	// "Есть продажа - нет открутки!"
    	$this->updatePeriodTotals();
    	
    	
    	
    	// 2. ДО периоде. Тоталы по логинам
    	$this->enfillPredPeriodTotals();
    	
    	 	
    	
    	
    	
  		//$this->companies = $this->getCompaniesWithSales();
    	
    	
    }

    public function getCompanySales( $company)
    {
    	$DS = $this->getBaseSaleQuery();
    	
    	$DS->selectDistinct( "S.ID, 
    			S.name, 
    			S.FK_control_month as month,
    			P.total")
    		->where("E.ID IN (" . implode(', ', $this->kindList()). ")")
    		->andWhere("C.ID = $company" );
    	 
    	$data = $DS->queryAll();
    	
    	
    	
    	$R = [];
    	foreach ( $data as $line)
    	{
    		    		
    		$R[ $line['ID']] =  (object) $line;
    	}
    	 
    	return $R;
    }
    
    
    private function getCompaniesWithSales()
    {
    	$DS = $this->getBaseSaleQuery();
    	$DS->selectDistinct( "C.ID, C.name")->where("E.ID IN (" . implode(', ', $this->kindList()). ")");
    	 
    	$data = $DS->queryAll();
    	$R = [];
    	foreach ( $data as $line)
    	{
    		$R[ $line['ID']] = $line['name'];
    	}
    	
    	return $R;
    }
    
    private function getBaseSaleQuery()
    {
    	$DS = Yii::app()->db->createCommand();
    	$DS->from('{{sales_products}} P')
    	->join('{{sales}} S', 'P.FK_sale = S.ID')
    	->join('{{sales_products_kinds}} SK', 'P.FK_sale_product_kind = SK.ID')
    	->join('{{companies}} C', 'S.FK_company = C.ID')
    	->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
    	->leftJoin( '{{sales_summaries}} SMM', 'SMM.FK_sale = S.ID');
    	 
    	$DS->where('P.active > 0')
    	->andWhere('S.active > 0')
    	->andWhere('C.active > 0')
    	->andWhere('S.FK_sale_state != 3')
    	->andWhere('S.W_lock = "Y"');
    
    	return $DS;
    }

    
  
    
    
	
	
    
   
}