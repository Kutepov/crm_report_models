<?php

class ClientSegmentationReportForm extends CFormModel
	implements InterfaceReportForm
{
    public $monthStart;
    public $monthEnd;
    public $field = 4;
    
    public $companies = [];
    public $monthlyData = [];
    public $timingData = [];

    /**
     * Declares the validation rules.
     */
    public function rules()
    {
        return array(// name, email, subject and body are required

        );
    }
    
    public function checkAccess( $actions )
    {
    	return Yii::app()->accDispatcher->accessToClasses( [ 'ClientSegmentationReportForm' => 'R'] );
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
    
    public function prepareParams( &$arrData)
    {
    	
    }

    public function setDefaults()
    {
        $this->monthStart = Month::getQueryID(date( "m"), date("Y")) - 6;
        $this->monthEnd = Month::getQueryID(date( "m"), date("Y")) - 1;
        
		$this->performReport();
    }
    
        
    protected function prepareReport()
    {
    	
    }
    public function defaultReport()
    {
    	
    }


    public function performReport()
    {
    	$this->companies = $this->getDirection();
    	$this->monthlyData = $this->getMonthlyData();
    	
    	$this->timingData = $this->getTimingData();
 			        
    }

	protected function getField()
	{
		if ($this->field == 1)
			return 'P.total';
		elseif ($this->field == 2)
			return '(1-(P.outlay+P.discost)/100) * P.total/(1-P.discost/100)';
		elseif ($this->field == 3)
			return '(1-(P.outlay2+P.discost)/100) * P.total/(1-P.discost/100)';
		else 
			return '(1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)';
	}
    
    
    private function getDirection()
    {
    	//Общая часть
        $DS = Yii::app()->db->createCommand()
            ->select('sum(' . $this->getField() . ') as S, S.FK_company, C.name')
            ->from('{{sales_products}} P')
            ->join('{{sales}} S', 'P.FK_sale = S.ID')
            ->join('{{companies}} C', 'C.ID = S.FK_company');

        $DS->where('P.ID > 0')
            ->andWhere('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('S.FK_sale_state != 3')
            ->andWhere('P.FK_sale_product_kind != 17')
            ->andWhere('P.FK_sale_product_kind != 19')
            ->order('S desc')
            ->group('S.FK_company, C.name');


        $DS->andWhere("S.FK_control_month >= {$this->monthStart}");
        $DS->andWhere("S.FK_control_month <= {$this->monthEnd}");
        $DS->andWhere('S.W_lock = "Y"');

        $data = $DS->queryAll();
        $R = [];
        
        foreach ( $data as $line )
        {
        	$R[ $line[ 'FK_company' ]] = [
        		'title'	=>	$line[ 'name'],
        		'total'	=>	$line[ 'S']
        	];
        }
        return $R; 	
    }
    
    
    private function getTimingData()
    {
		$timeStart = Month::getInnerDate($this->monthStart, '1990-01-01') . " 0:0:0";
		$timeEnd = Month::getInnerDate($this->monthEnd, '2100-01-01') . " 23:59:59";
    	
    	$DS = Yii::app()->db->createCommand()
    		->select('sum(TT.data) as T, TT.FK_author, T.FK_company')
	   		->from('{{timetable}} TT')
    		->join('{{tasks}} T', 'TT.FK_task = T.ID');
    		
    		$DS->where('TT.type = 1')
    		
    		->andWhere('TT.date_add >= "' . $timeStart . '"')
    		->andWhere('TT.date_add <= "' . $timeEnd . '"')
    		->group('TT.FK_author, T.FK_company');
    
    	$data = $DS->queryAll();
    	$users_OMPA = Yii::app()->accDispatcher->getTriggerGroupList( 'mediaplaner' );
    	$users_SEO = Yii::app()->accDispatcher->getTriggerGroupList( 'seo' );
    	$users_WEB = Yii::app()->accDispatcher->getTriggerGroupList( 'web' );
    	$R = [];
    	
    	foreach ( $data as $line)
    	{
    		if (!isset($R[ $line['FK_company']]))
    				$R[ $line['FK_company'] ] = [ 
        				'mpa'	=>	0,
    					'seo'	=>	0,
    					'web'	=>	0
    				];
    		
    		if( in_array($line['FK_author'], $users_OMPA)) $R[ $line['FK_company'] ]['mpa'] += $line[ 'T'];
    		if( in_array($line['FK_author'], $users_SEO))  $R[ $line['FK_company'] ]['seo'] += $line[ 'T'];
    		if( in_array($line['FK_author'], $users_WEB))  $R[ $line['FK_company'] ]['web'] += $line[ 'T'];
    	}
    	
    	return $R;
    }
    
    public function getTimingValue( $FK_company, $field )
    {
    	if( !isset( $this->timingData[ $FK_company])) return 0;
    	return  $this->timingData[ $FK_company][ $field] / 60; 
    }
    
    private function getMonthlyData()
    {
    	//Общая часть
    	$DS = Yii::app()->db->createCommand()
    	->select('sum(' . $this->getField() . ') as VD,
    			sum( case when(E.is_long = 1 AND K.FK_category = 1) then ' . $this->getField() . ' else 0 end ) as R_long,
    			sum( case when(E.is_long = 0 AND K.FK_category = 1) then ' . $this->getField() . ' else 0 end ) as R_short,
    			sum( case when(K.FK_category = 3) then ' . $this->getField() . ' else 0 end ) as SEO,
    			sum( case when(K.FK_category = 2 || K.FK_category = 8) then ' . $this->getField() . ' else 0 end ) as WEB, 
    			S.FK_company, S.FK_control_month')
    			
    	->from('{{sales_products}} P')
    	->join('{{sales}} S', 'P.FK_sale = S.ID')
    	->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
    	->join('{{sales_products_kinds}} K', 'P.FK_sale_product_kind = K.ID');
    	
    
    	$DS->where('P.ID > 0')
    	->andWhere('P.active > 0')
    	->andWhere('S.active > 0')
    	->andWhere('S.FK_sale_state != 3')
    	->andWhere('P.FK_sale_product_kind != 17')
    	->andWhere('P.FK_sale_product_kind != 19')
    	
    	->group('S.FK_company, S.FK_control_month');
    
    
    	$DS->andWhere("S.FK_control_month >= {$this->monthStart}");
    	$DS->andWhere("S.FK_control_month <= {$this->monthEnd}");
    	$DS->andWhere('S.W_lock = "Y"');
    
    	$data = $DS->queryAll();
    	$R = [];
    
    	foreach ( $data as $line )
    	{
    		if(!isset( $R[ $line['FK_company']])) $R[ $line['FK_company']] = [];
    		
    		$R[ $line['FK_company']] [ $line['FK_control_month'] ] = [
        		'VD' => $line['VD'],
        		'R_short' => $line['R_short'],
        		'R_long' => $line['R_long'],
        		'SEO' => $line['SEO'],
        		'WEB' => $line['WEB'],
    		];
    	}
    	return $R;
    }
    
    public function getMonthlyValue( $FK_company, $FK_month, $field = 'VD')
    {
    	if( isset( $this->monthlyData[$FK_company][ $FK_month ] ))
    		
    		return $this->monthlyData[$FK_company][ $FK_month ][$field];
    	else 
    		return false;
    	
    }
    

    
    public function getMonthList()
    {
    	return Month::getMonthNameList(
    			$this->monthStart,
    			$this->monthEnd
    	);
    }
    
    
    public function getStatus( $FK_company )
    {
    	if (
        	!$this->getMonthlyValue($FK_company, $this->monthEnd) &&
    		!$this->getMonthlyValue($FK_company, $this->monthEnd-1)
    	)
    		return false;
    	return true;
    		    	
    }
	public function getPercentage( $FK_company )
	{
		$n = 0;
		for( $i = $this->monthStart; $i <= $this->monthEnd; $i++)
			if($this->getMonthlyValue( $FK_company, $i) !== false) $n++;
		
		return $n/( $this->monthEnd - $this->monthStart + 1); 
	}
    

	public function getMidi($FK_company, $field = 'VD'){
		$n = 0;
		$S = 0;
		for( $i = $this->monthStart; $i <= $this->monthEnd; $i++)
			if($V = $this->getMonthlyValue( $FK_company, $i, $field) ) 
			{
				$S += $V; 
				$n++;
			}
		
		return $n>0 ? ($S / $n) : 0;
	}
	
	public function getTotal($FK_company, $field = 'VD'){
		
		$S = 0;
		for( $i = $this->monthStart; $i <= $this->monthEnd; $i++)
			if($V = $this->getMonthlyValue( $FK_company, $i, $field) )
			{
				$S += $V;
			}
	
			return $S;
	}
	
	
    
   
}