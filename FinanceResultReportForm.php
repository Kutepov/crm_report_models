<?php

class FinanceResultReportForm extends CFormModel
	implements InterfaceReportForm
{
    protected $ready = 0;
	public $monthStart;
	public $monthEnd;
	public $yearStart;
	public $yearEnd;
	
	protected $mStart;
	protected $mEnd;
	
	protected $_sales = [];
	protected $_sales_vd = [];
	protected $_sales_bycat = [];
	protected $_spends = [];
	protected $_spends_np = [];
	
	private $pllist = [
			2121	=>	'Яндекс',
			5342	=>	'ООО "Мэйл.Ру"',
			5345	=>	'ООО "ВКонтакте"',
			5354	=>	'ООО «Гугл»',
			6109	=>	'Facebook',
			
	];
    /**
     * Declares the validation rules.
     */
    public function rules()
    {
        return array(
        		array('monthStart, monthEnd, yearStart, yearEnd', 'required'),

        );
    }
    
    
    public function prepareParams(&$arrData)
    {	
    	
    }
    
    public function ready()
    {
    	return $this->ready;
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
    		$this->monthStart = date('m');
    		$this->monthEnd = date('m');
    		$this->yearStart = date('Y');
    		$this->yearEnd = date('Y');
    	
    }
    
    public function defaultReport()
    {
    	$this->performReport();
    	
    	
    	
    }
    
        
    protected function prepareReport()
    {
    	$monthLimits = Month::getQueryLimits($this->monthStart, $this->yearStart, $this->monthEnd, $this->yearEnd);
    	$this->mStart = $monthLimits['min'];
    	$this->mEnd = $monthLimits['max'];
    }


    
    
  
    
    
    public function performReport()
    {
    	$this->prepareReport();
    	
    	$this->_sales = $this->getMonthlySales('total');
    	
    	$this->_sales_vd = $this->getMonthlySales('vd');
    	$this->_spends = $this->getMonthlySpends();
    	$this->_spends_np = $this->getMonthlySpends(1);
    	$this->_sales_bycat = $this->getMonthlySalesByContract('total');
    	
    	$this->ready = 1;
    }


    public function getMonthlySaleValue( $k )
    {
    	return $this->arrResult($this->_sales, $k);
    }
    public function getMonthlySaleVDValue( $k )
    {
    	return $this->arrResult($this->_sales_vd, $k);
    }
    public function getMonthlySaleCatValue( $catId, $k )
    {
    	if( isset($this->_sales_bycat[$catId]))
    		return $this->arrResult($this->_sales_bycat[$catId], $k);
    	else return 0;
    }
    public function getMonthlySpendValue( $k )
    {
    	return $this->arrResult($this->_spends,$k);   	
    }
    public function getMonthlySpendValueNPL( $k )
    {
    	return $this->arrResult($this->_spends_np,$k);
    }
    
    protected function arrResult( $arr, $k)
    {
    	if( isset( $arr[ $k ]))
    		return $arr[$k];
    	else
    		return 0;
    }
    
    public function getMonthList()
    {
    	//3. Список месяцев
    	return Month::getMonthNameList( $this->mStart, $this->mEnd );
    	
    }
    
    public function getParameterList( $p )
    {
    	switch ( $p )
    	{
    		    				
    		case 'monthList':
    			return Month::getMonths();
    			
    		case 'yearList':
    			return Month::getYears();
    			
    		case 'category':
    			return DocumentCategory::getDDActiveList('category');
    			
    		default:
    			return array();
    	}
    	
    }
    
  
    public function getMonthlySpends( $filterPl = 0)
    {
    	$DS = $this->getSpendQuery( $this->mStart, $this->mEnd);
    	if( $filterPl )
    		$DS->andWhere( ' NOT (
					C.FK_company IN(' . implode( ', ', array_keys($this->pllist)). ')
					AND I.FK_category = 1
			)');
    	
    	$DS->select( 'sum(R.total) as S, R.FK_month as M')->andWhere('R.credit = 0')->group('M');
    	
    	$R = [];
    	$rows = $DS->queryAll();
    	
    	foreach ($rows as $line)
    		$R[ $line['M']] = $line['S'];
    		
    	return $R;
    }
    
    public function getMonthlySales( $field )
    {
    	$DS = $this->getSaleQuery( $this->mStart, $this->mEnd);
    	$this->strictSales( $DS);
    	
    	$DS->select( 'sum('.$this->getField($field).') as S, S.FK_control_month as M')->group('M');
    	
    	$R = [];
    	$rows = $DS->queryAll();
    	
    	foreach ($rows as $line)
    		$R[ $line['M']] = $line['S'];
    	
    	return $R;
    }
    
    public function getMonthlySalesByContract($field)
    {
    	$DS = $this->getSaleQuery( $this->mStart, $this->mEnd);
    	$this->strictSales( $DS);
    	
    	$DS->select( 'sum('.$this->getField($field).') as S, D.FK_category as F, S.FK_control_month as M')->group('M, F');
    	
    	$R = [];
    	$rows = $DS->queryAll();
    	
    	foreach ($rows as $line)
    	{
    		if(!isset( $R[ $line['F']])) $R[ $line['F']] = [];
    		$R[ $line['F']][ $line['M']] = $line['S'];
    	}
    		
    	return $R;
    	
    }
    
    protected function getSpendQuery( $m1, $m2)
    {
    	$DS = Yii::app()->db->createCommand()
    		
			->from('{{spending_register}} R')
			->join('{{contracts}} C', 'R.FK_contract = C.ID')
			->leftJoin('{{spending_items}} I', 'R.FK_item = I.ID')
					  
			->where( 'R.FK_month >= ' . $m1)
			->andWhere( 'R.FK_month <= ' . $m2);
					  
    	return $DS;
    }
    	
    protected function getField( $field )
    {
    	if (strtolower( $field) == 'md')
    		return '(1-(P.outlay2+P.discost)/100) * P.total/(1-P.discost/100)';
    	if (strtolower( $field) == 'vd')
    		return '(1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)';
    	else
    		return 'P.total';
    }
    
	
    protected function strictSales( &$DS)
    {
    	$DS->andWhere( 'S.W_lock = "Y"');
    }
    
    protected function getSaleQuery( $m1, $m2)
    {
    	$DS = Yii::app()->db->createCommand();
    	$DS->from('{{sales}} S')
    	->join('{{contracts}} D', 'S.FK_contract = D.ID')
    	->join('{{companies}} C', 'S.FK_company = C.ID')
    	->join('{{sales_products}} P', 'P.FK_sale = S.ID')
    	->join('{{spk_elaborations}} E', 'E.ID = P.FK_sale_prod_elaboration')
    	
    	->where('P.active > 0')
    	->andWhere('S.active > 0')
    	->andWhere('S.FK_sale_state != 3')
    	->andWhere('S.FK_control_month >= '.$m1)
    	->andWhere('S.FK_control_month <= '.$m2);
    	
    	return $DS;
    }
    
   
}