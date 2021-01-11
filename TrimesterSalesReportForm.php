<?php

class TrimesterSalesReportForm extends CFormModel
	implements InterfaceReportForm
{
	const HMOON = 6;
	public $DEBUG = 0;
	
	public $reportYear;
	public $reportTrimester;
	
	public $FK_managers;
	public $ready = 1;
	
	
	public $mStart;
	private $shortSalesData = [];
	private $longSalesData = [];
		
	private $dictionaryFeature = [];
	private $dictionaryKind = [];
	public $dictionaryUsers = [];
	
	private $saleBeforeP = [];
	private $saleIntoP = [];
	
	private $companyBeforeP = [];
	private $companyIntoP = [];
	
	private $managers = [];

	public $sumMonth = [];

	
	// компанейный блок. Для расчетов и сокращений ))
	private $_company_Tops;
    /**
     * Declares the validation rules.
     */
    public function rules()
    {
        return array(
        	['DEBUG, reportYear, reportTrimester, FK_managers', 'safe'],

        );
    }
        
    public function prepareParams(&$arrData)
    {
    }
    
    public function checkAccess( $actions )
    {
    	return true; //Yii::app()->accDispatcher->accessToClasses( [ 'ClientSegmentationReportForm' => 'R'] );
    }


    public function attributeLabels()
    {
        return array(
        );
    }

    public function setDefaults()
    {
    	$this->reportYear = date("Y");
    	$this->reportTrimester = date( "m")%3 ;
    }
    
    public function defaultReport()
    {
    	$this->performReport();
    }
    
        
    protected function prepareReport()
    {
    	$this->mStart = Month::getQueryID( 3*$this->reportTrimester + 1, $this->reportYear);
    		
    	if( count($this->FK_managers) == 0 )
    		$this->FK_managers = array_keys($this->getParameterList('FK_managers'));
    }


   
    public function performReport()
    {
    	$this->prepareReport();
    	
    	$this->_fillFirstTimesTable(); // вспомсписки для проверки "первости"
 			      
    	$this->_fillLongSalesData();
    	$this->_fillShortSalesData();
    	
 	
    	//$crit = $product->FK_sale_product_kind * 1000 + $product->elaboration->FK_feature1;
    	$this->dictionaryKind = SaleProductKind::getDDActiveList( 'name');
		$this->dictionaryFeature = Features::getDDActiveList( 'feature1');
		$this->dictionaryUsers = $this->_fillUsersDictionary();
    }

    private function _fillUsersDictionary()
    {
    	$list = User::model()->findAll( 'FK_access_group != 5');
    	$R = [];
    	foreach( $list as $user)
    		$R[ $user->ID ] = [
    			$user->getName(),
    			$user->getAbbr(),
    		];
    	
    		
    	return $R;
    	
    	
    }
    
    
    /**
     *  расшифровка уточнения вид+категория
     * @param unknown $crit
     * @return string
     */
    public function getPrCriteria( $crit)
    {
    	//$crit = $product->FK_sale_product_kind * 1000 + $product->elaboration->FK_feature1;
    	$pk = (int)($crit / 1000);
    	$ftr = $crit % 1000;
    	
    	return 
    		$this->dictionaryFeature[ $ftr ] . " : " . $this->dictionaryKind[ $pk];
    }

    
    
    
    public function calcCompanyData( $companyId)
    {
    	$this->_comapny_fillCompanyTops( $companyId);
    	
    	
    }
    
    /**
     * лучший результат до периода + суммы в периоде
     * @param unknown $companyId
     */
    private function _comapny_fillCompanyTops( $companyId)
    {
    	$DS = $this->getBaseStructureQuery( $this->mStart - 12, $this->mStart+2);
    	$DS->andWhere('S.FK_company = ' . $companyId )
    	->andWhere('P.FK_sale_product_kind NOT IN (10, 19)')
    	->select(  'S.FK_control_month as M, sum((1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)) as VD' )
    	->group( 'S.FK_control_month');
    	 
    	$rows = $DS->queryAll();
    	 
    	$S = 0;
    	$tops = [];
    	 
    	foreach( $rows as $line)
    	{
    		if( $line['M'] < $this->mStart && $line['VD'] > $S) 
    			$S = $line['VD'];
    		
    		if( $line['M'] >= $this->mStart )
    		{
    			$tops[ $line['M']] = $line['VD'];
    		}
    	}
    	
    	$tops[0] = $S;
    	$this->_company_Tops = $tops;
    }
    
    public function getMonthTotalData( $companyId,  $month)
    {
    	if( isset($this->_company_Tops[ $month]))
    		return $this->_company_Tops[ $month];
    	
    	return 0;
    }
    
    public function getMonthVolumePlus( $companyId,  $month)
    {
    	$current = $this->getMonthTotalData( $companyId,  $month);

        $prevMax = $this->_company_Tops[ 0];

        foreach ($this->_company_Tops as $k => $v)
        {
            if( $k>0 && $k<$month && $v > $prevMax)
                $prevMax = $v;
        }

        //Если в прошлом месяце была контекстная продажа, она не вычитается, убираем ее из $prevMax
        if (!empty($this->sumMonth[$month-1])) {
            foreach ($this->sumMonth[$month-1] as $crit => $sum) {
                $isContext = in_array(  $crit % 1000, [ 2,5 ]  );
                if ($isContext)
                    $prevMax -= $sum;
            }
        }

        return (($current - $prevMax) > 0)  ? ($current - $prevMax) : 0;
    }
    
    
    public function getMonthVolumePlusByMPP( $companyId,  $month)
    {
    	$allPlus = $this->getMonthVolumePlus( $companyId,  $month);
    	
    	// В доверенном периоде - все плюсы - плюсы 
    	if( $this->inHoneyMoon($companyId, $month))
 			return  $allPlus;
    	
    	$manager = $this->managers[ $companyId ];
    	$byMPP = 0;
    	
    	foreach( $this->longSalesData[ $companyId] as $critKey => $critData)
    	{
    		if( isset( $critData[$month]['val'][$manager]) )
    			$byMPP += $critData[$month]['val'][$manager];
    	} 
    	
    	return min( $allPlus, $byMPP);
    }
    
    public function getPreviuosMax( $company )
    {
    	$start = Month::getQueryID( 3*$this->reportTrimester + 1, $this->reportYear);
    	$S = 0; 
    	for ( $i=$start-3; $i < $start; $i++)
    		if( $this->longSalesData[ $company][$i] > $S )
    			$S = $this->longSalesData[ $company][$i];
    		
    	return $S;
    }
    
    
    public function calcAddNew( $companyId, $critElab, $month )
    {
    	
    	if( !$this->getSaleDataFirst($companyId, $critElab, $month)) return 0;
    	
    	$hm = $this->inHoneyMoon($companyId, $month);
    	
    	$sellers = array_keys($this->longSalesData[ $companyId][ $critElab][ $month]['val']);
    	
    	if( $hm || in_array($this->managers[ $companyId], $sellers))
    			return $this->getCellData($companyId, $critElab, $month);
    	else 
    		return 0;
    	
    }
    
    
    public function calcLoss(  $companyId, $critElab, $month )
    {
    	$isContext = (  $critElab % 1000 == 2 );
    	
    	$new = 0;
    	
    	for( $m = $month-3; $m<$month; $m++ )
    	{
    		if( $this->getSaleDataFirst( $companyId, $critElab, $m) )
    			$new = $m;
    	}
    	
    	
    }
    
    
    
    public function calcMPPDiffer( $companyId, $critElab, $month )
    {	
    	$isContext =  in_array(  $critElab % 1000, [ 2,5 ]  );
    	$manager = $this->managers[ $companyId ];
    	
    	$new = 0;
    	for( $m = $month-3; $m<=$month; $m++ )
    	{
    		if( 
    			$this->getSaleDataFirst( $companyId, $critElab, $m) &&
    			isset( $this->longSalesData[ $companyId][$critElab][$m]['val'][ $manager] ) // новая продажа от мпп
    		)
    			
    			$new = $m;
    	}
    	
    	// новых не было! или контекст - смотрим дальше
    	if(
    		$new == 0 || 
    		((($month - $new) == 3 ) && ! $isContext ) ||
    		
    		( 	// контекст, вторая продажа, сумма до 60% - пропускъ	
    			$isContext && 
    			(($month - $new) == 1 )
                &&    			( $this->getCellData( $companyId, $critElab, $month) <= 0.6*$this->getCellData( $companyId, $critElab, $month-1))
    		) || 
    			
    		( 	// контекст, вторая продажа, сумма до 60% - пропускъ
    			$isContext &&
    			(($month - $new) == 3 )
                &&    			( $this->getCellData( $companyId, $critElab, $new+1) >= 0.6*$this->getCellData( $companyId, $critElab, $new))
    		)
    	) {
    	    return 0;
        }
    	$curr = (double)$this->getCellData( $companyId, $critElab, $month);
    	$prev = (double)$this->getCellData( $companyId, $critElab, $month-1);

    	if( $isContext && 
    		(($month - $new) == 2 )
            &&( $this->getCellData( $companyId, $critElab, $new+1) <= 0.6*$this->getCellData( $companyId, $critElab, $new))
    	)
    	{	// обработка непотери "второго месяца контекст"
    		
    			$prev = (double)$this->getCellData( $companyId, $critElab, $month-2);
    	}

    	//Добавляем продажу в месяце для компании
    	$this->sumMonth[$month][$critElab] = $curr;

    	if( ( $prev != $curr)  ) // снижение
    		return ($curr - $prev);

    		
		return 0;    	
    }
    
    
    public function calcExtraVal( $companyId, $month, $topVal )
    {
    	for( $m = $this->mStart; $m<$month; $m++ )
    	{
    		$total = $this->getSaleTotalData($companyId, $m);
    		if( $total > $topVal)
    			$topVal = $total;
    	}
    	
    	$curr = $this->getSaleTotalData($companyId, $month) ;
    	if( $curr > $topVal )
    		return $curr - $topVal;
    	
    	return 0;
    }
    
    public function calcExtraNews( $companyId, $month, $topVal )
    {
    	$R = 0;
    	
    	foreach( $this->longSalesData[ $companyId] as $elabKey => $v )
    	{
    		if( $this->getSaleDataFirst( $companyId, $elabKey, $month))
    			$R += $this->getCellData($companyId, $elabKey, $month);
    		
    	}
    	 
    	   	 
    	return $R;
    }
    
    public function getPreviuosMiddle( $company )
    {
    	$start = Month::getQueryID( 3*$this->reportTrimester + 1, $this->reportYear);
    	
    	$isnew = $this->getNewCompaniesMark([$company], -3);
    	
    	$N = 0;
    	if( count($isnew))
    		$S = false;
		else
			$S = 0;
    	    	
    	for ( $i=$start-3; $i < $start; $i++)
    	{
    		if( $S !== false )
    			$N++;
    	
    		if( $this->longSalesData[ $company][$i] > 0 )
    		{
    			if( $S === false )
    			{
    				$N = 1;
    				$S = $this->longSalesData[ $company][$i];
    			}
    			else
    				$S += $this->longSalesData[ $company][$i];
    		}
    			
    	}
    	if( $N)
    		return $S / $N;
    	else 
    		return 0;
    }
    
    private function _periodSalesCriteria( $start, $end)
    {
    
    	$criteria = new CDbCriteria();
    	$criteria->with = ['sale', 'elaboration', 'sale.company'];
    	$criteria->addCondition('sale.FK_sale_state != 3');
    	$criteria->addCondition('sale.active = 1');
    	$criteria->addCondition('t.active = 1');
    	$criteria->addCondition('t.is_unique = 0');
    	$criteria->addCondition('sale.FK_control_month >= ' . $start);
    	$criteria->addCondition('sale.FK_control_month <= ' . $end);
    
    	$criteria->join = 'LEFT JOIN `t_sales_summaries` `smms` ON `smms`.FK_sale = `t`.ID';
    	$criteria->addCondition('sale.W_lock = "Y"');
    	
    	//$criteria->addCondition('(( sale.W_lock = "Y" ) OR ( sale.FK_control_month >= '. ($this->mEnd - 1) . '))');
    	$criteria->distinct = true;
    
    	if( count( $this->FK_managers ))
    	{
    		$criteria->addInCondition('company.FK_manager', $this->FK_managers);
    	}
    	
    
    	return $criteria;
    }
    
    private function _fillLongSalesData()
    {
    	
    	$criteria = $this->_periodSalesCriteria( $this->mStart-5, $this->mStart+2);
    	
    	$criteria->addCondition( 't.FK_sale_product_kind NOT IN (10, 19)');
    	
    	$dataProvider = new CActiveDataProvider('SaleProduct', array(
    			'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 500);
    	
    	$data = [];
    	
    	
    	foreach ( $iterator as $product )
    	{
    		$crit = $product->FK_sale_product_kind * 1000 + $product->elaboration->FK_feature1;
    		$company = $product->sale->FK_company;
    		$M = $product->sale->FK_control_month;
    			
    		$VD = $product->calcVD();

    		if($VD == 0) continue;
    		
    		if( !isset( $data[ $company ][$crit][$M]))
    			 $data[ $company ][$crit][$M] = ['first'=>0, 'val'=>[] ];
    		
    		if( !isset( $data[ $company ][$crit][$M]['val'][ $product->sale->FK_manager ]))
    			$data[ $company ][$crit][$M]['val'][ $product->sale->FK_manager ] = 0;
    		
    		$this->managers[ $company ] = $product->sale->company->FK_manager;
    		
    		$data[ $company ][$crit][$M]['val'][ $product->sale->FK_manager ] += $VD; 
    		    		
    		// не первый?
    		if( $this->_isFirstSaleProduct($product))
    			$data[ $company ][$crit][$M][ 'first' ] = 1;
    		
	
    	}
    	
    	$this->longSalesData = $data;

    	
    }
    
    public function getSaleTotalData( $company, $M)
    {
    	$R = 0;
    	foreach  ($this->longSalesData[ $company ] as $k => $v)
    	{
    		if( !isset( $this->longSalesData[ $company ][$k][$M]))
    			continue;
    	 
    		$R += $this->getCellData( $company,$k,$M);
    	}
    	return $R;
    
    } 
    
    public function inHoneyMoon( $companyId, $month)
    {
    
    	
    	if( !isset($this->companyIntoP[ $companyId ])) 
    		return false;
    	
    	$fip = $this->companyIntoP[ $companyId ];
		
    	
    	if(
    		!isset( $this->companyBeforeP[ $companyId ]) &&
    		( $month >= $fip)	&&
    		( $month < ($fip + self::HMOON))
    	)
    	return true;
    	
    	return false;
    }
    
    public function skipThisCompany( $companyId, $manager )
    {	
    	return false;
    	
    	// "доверенный" период
    	if( $this->inHoneyMoon( $companyId, $this->mStart))
    		return false;
    	
    	
    	// 
    	// есть длинные продажи в интервале -4 - +2
    	// или есть продажи от менеджера	
    	foreach (  $this->longSalesData[ $companyId ] as $critKey=>$critData  )
    	{
    		foreach ($critData as $monthKey => $monthData)
    		{
    			if( $monthKey <= ($this->mStart-3)) continue;
    			
    			if( $monthData['first'] == 1 &&
					(
						isset($monthData['val'][ $manager ]) ||
						$this->inHoneyMoon( $companyId, $monthKey)
					)
    			)
    				return false;
    			
    			if( $monthKey >= $this->mStart 
    					&& isset($monthData['val'][$manager]) 
    					&& ($monthData['val'][$manager] > 0) )
    				return false;
    		}
    	}
    	
    	
    	
    	//ничего нет - компания нам не нужна
    	return true;
    }
    
    public function getCellData( $k, $crit, $M)
    {
    	if( !isset( $this->longSalesData[ $k ][$crit][$M]))
    		return false;
    	
    	$R = 0;
    	foreach( $this->longSalesData[ $k ][$crit][$M]['val'] as $u => $s)
    		$R += $s;
    		
    	return $R;
    		
    }
    
    
    public function getCellAuthority( $k, $crit, $M)
    {
    	if( !isset( $this->longSalesData[ $k ][$crit][$M]))
    		return false;
    	 
    	$R = [];
    	foreach( $this->longSalesData[ $k ][$crit][$M]['val'] as $u => $s)
    	{
    		$R[] = CrmGraph::userSqS($this->dictionaryUsers[ $u ][1], $this->dictionaryUsers[ $u ][0], ['style'=>'margin: 5px;']); 
    		//BsHtml::tag('span', [ 'title' => $this->dictionaryUsers[ $u ][0]], $this->dictionaryUsers[ $u ][1]);
    	}
    
    	return implode(' ', $R);
    
    }
    
    public function getSaleDataFirst( $k, $crit, $M)
    {
    	return ( 
    		isset( $this->longSalesData[ $k ][$crit][$M]['first']) &&
    			( $this->longSalesData[ $k ][$crit][$M]['first'] > 0)
    	);
    }
    
    public function getCompanyLongs( $k )
    {
    	if( !isset( $this->longSalesData[ $k ]))
    		return [];
    	
    	return array_keys( $this->longSalesData[ $k ]);
    	
    	
    }
    
    public function _isFirstSaleProduct( SaleProduct $P )
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
    
    
    private function _fillShortSalesData()
    {
    	$start = Month::getQueryID( 3*$this->reportTrimester + 1, $this->reportYear);
    	
    	
    	$criteria = $this->_getBaseProductCriteria();
    	$this->_criteriaWithinPeriod( $criteria);
    	
    	//$criteria->addCondition( 't.FK_sale_product_kind IN (10, 19)');
    	
    	$dataProvider = new CActiveDataProvider('SaleProduct', array(
    		'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 100);
    	
    	   	
    	$data = [];
    	foreach ( $iterator as $product)
    	{
    		
    		if( !isset( $data[ $product->sale->FK_company ]))
    			$data[ $product->sale->FK_company ] = [ $start => 0, $start+1 => 0, $start+ 2 => 0];
    		
    		$data[ $product->sale->FK_company ][ $product->sale->FK_control_month ] += $product->calcVD();
    	}
    	    	
    	$this->shortSalesData = $data;
    }
    
    
    private function _getDomainShorts( $company, $month)
    {
    	if( isset( $this->shortSalesData[ $company][ $month]))
    		return  $this->shortSalesData[ $company][ $month];
    	return 0;
    }
    
    private function _getLongs( $company, $month)
    {
    	if( isset( $this->longSalesData[ $company][ $month]))
    		return  $this->longSalesData[ $company][ $month];
    		return 0;
    }
    
    
    
    public function getData( $type, $company, $month)
    {

    	switch( $type)
    	{
    	
    		case 1:
    			return $this->_getLongs( $company, $month);
    		
    		case 2:
    			return $this->_getDomainShorts( $company, $month);
    	
    	}
    	
    	return 0;
    	 
    	
    }
    
    private function _criteriaWithinPeriod( &$criteria, $mod1=0, $mod2=0)
    {
    	$start = Month::getQueryID( 3*$this->reportTrimester + 1, $this->reportYear);
    	$criteria->addCondition( 'sale.FK_control_month >= ' . ($start + $mod1));
    	$criteria->addCondition( 'sale.FK_control_month <= ' . ($start + 2 + $mod2));
    }
    
    private function _getBaseProductCriteria()
    {
    	$criteria = new CDbCriteria();
    	$criteria->with = array( 'sale', 'sale.company', 'elaboration');
    	$criteria->addCondition('t.active > 0');
    	$criteria->addCondition('sale.active > 0');
    	$criteria->addCondition('sale.FK_sale_state != 3');
    	$criteria->addInCondition('company.FK_manager', $this->FK_managers);
    	$criteria->addCondition( 't.FK_sale_product_kind IN (10, 19) OR t.is_unique = 1');
    	
    	return $criteria;
    }
    
    public function getParameterList( $p )
    {
    	switch ( $p )
    	{
    		case 'reportYear':
    			return Month::getYears();
    			
    			
    		case 'reportTrimester':
    			return [
    				0	=>	'Январь - Март',
    				1	=>	'Апрель - Июнь',
    				2	=>	'Июль - Сентябрь',
    				3	=>	'Октябрь - Декабрь',
    			];
    			
    		case 'FK_managers':
                $list = Company::model()->getColumnList('FK_manager', 'FK_manager>0 AND active=1 AND hidden=0');

                if (Yii::app()->user->userIsROP) {
                    $R = User::getDDList( 'fio', 'ID in ('. implode(',', $list).') AND (FK_manager = ' . Yii::app()->user->id . ' OR ID = ' . Yii::app()->user->id . ')');
                } else {
                    $R = User::getDDList( 'fio', 'ID in ('. implode(',', $list).')');
                }


    			break;
    			
    		default:
    			$R = array();
    	}
    
    	natsort($R);
    	return $R;
    }

   
    public function getTrimesterMonths( $ext = 0)
    {
    	if( $ext )
    	{
    		return Month::getMonthNameList($this->mStart - 5, $this->mStart+2, 1);
    	}
    	else
    		
    		return Month::getMonthNameList($this->mStart, $this->mStart+2, 1);
    }
  
    
    public function getRowChanges( $companyId, $critElab)
    {
    	$R = [];
    	for ( $m = $this->mStart; $m <= $this->mStart+2; $m++ )
    	{
    		$loss = $this->calcLoss( $companyId, $critElab, $m );
    		
    		if( $loss )
    			$R[ $m ] = [ 'loss' => $loss ];
    		   
    			
    		$add = $this->calcAddNew( $companyId, $critElab, $m );
    		if( $add)
    		{
    			if( isset( $R[$m]))
    				$R[ $m] = [];
    			$R[ $m][ 'new'] = $add;
    		}
    	}
    	
    	return $R;
    	
    }
    
    
    public function getCompaniesWithin( $category)
    {
    	
    	switch( $category)
    	{
    		case 1:
    			$list = array_keys($this->longSalesData);
    			break;
    			
    		case 2:
    			$list = array_keys($this->shortSalesData);
    			break;
    		
    		default:
    			$list = [];
    	
    			
    		
    	}
    	
    	$result = $this->_getCompanyNames( $list);
    	    	
    	return $result;

    }
	
    public function getNewCompaniesMark( $comp_keys, $mod=0 )
    {
    	$start = Month::getQueryID( 3*$this->reportTrimester + 1, $this->reportYear);
    	$DS = Yii::app()->db->createCommand()
    		->selectDistinct( 'S.FK_company')
    		->from( Sale::model()->tableName() . ' S' )
    		->where( 'S.active > 0')
    		->andWhere('S.FK_sale_state != 3')
    		->andWhere('S.W_lock = "Y"')
    		->andWhere('S.FK_control_month < :m1' )
    		->andWhere('S.FK_control_month >= :m2' );
    	
    	return $DS->queryColumn([ 
    			'm1' => $start+$mod,
    			'm2' => $start-16+$mod
    	]);
    	
    }
    
    
    
    private function _getCompanyNames( $list)
    {
    	$criteria = new CDbCriteria();
    	$criteria->select = ['ID', 'name'];
    	$criteria->with = ['manager'];
    	$criteria->addCondition('t.active > 0');
    	$criteria->addInCondition('t.ID', $list);
    	
    	$list = Company::model()->findAll($criteria);
    	
    	$R = [];
    	foreach( $list as $company)
    	{
    		
    		$R[ $company->ID ] = [ $company->name, CrmGraph::userSq($company->manager, ['pull'=>'right']), $company->FK_manager ]; 
    	}
    		
    		
    	return $R; //CHtml::listData( Company::model()->findAll($criteria), 'ID', 'name');
    	
    }
    
    
    public function getCompanyBestValue( $k)
    {
    	 $DS = $this->getBaseStructureQuery( $this->mStart - 12, $this->mStart-1);
    	 $DS->andWhere('S.FK_company = ' . $k )
    	 	->andWhere('P.FK_sale_product_kind NOT IN (10, 19)')
    	 	->select(  'S.FK_control_month as M, sum((1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)) as VD' )
    	 	->group( 'S.FK_control_month');
    	 
    	 $rows = $DS->queryAll();
    	 
    	 $S = 0;
    	 
    	
    	// print_r( $rows);
    	 
    	 foreach( $rows as $line)
    	 {
    	 	if( $line['VD'] > $S) $S = $line['VD'];
    	 }
    	 return $S;
    }
    
    
    private function getBaseStructureQuery( $start = 0, $end = 0)
    {
    	if( $start == 0) $start = $this->mStart;
    	if( $end == 0) $end = $this->mEnd;
    	 
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
    	->andWhere('P.is_unique = 0')
    	->andWhere('S.active > 0')
    	->andWhere('C.active > 0')
    	->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)') //без балансов!
    	->andWhere('S.FK_sale_state != 3');
    	 
    	return $DS;
    }
    
    private function _fillFirstTimesTable(  )
    {
    	$DS = $this->getBaseStructureQuery(  $this->mStart - 12 - 5, $this->mStart - 5 - 1 );
    	$DS->group( 'G, C');
    
    	$DSin = $this->getBaseStructureQuery( $this->mStart - 5, $this->mStart + 2 );
    	$DSin->group( 'G, C');
    
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
    	$RC = [];
    	
    	foreach ( $data as $line )
    	{
    		if ( !isset( $R[ $line['G'] ] ))
    			$R[ $line['G'] ] = [];
    		if ( !isset( $R[ $line['G'] ][ $line['C'] ] ))
    			$R[ $line['G'] ][ $line['C'] ] = $line[ 'M' ];
    		
    		if ( !isset( $RC[ $line['C'] ] ) || ( $RC[ $line['C'] ] < $line[ 'M' ]))
    			$RC[ $line['C'] ] = $line[ 'M' ];
    		
    	}
    	$this->saleBeforeP = $R;
    	$this->companyBeforeP = $RC;
    	 
    
    	$R = [];
    	$RC = [];
    	
    	$data2 = $DSin->queryAll();
    	foreach ( $data2 as $line )
    	{
    		if ( !isset( $R[ $line['G'] ] ))
    			$R[ $line['G'] ] = [];
    		if ( !isset( $R[ $line['G'] ][ $line['C'] ] ))
    			$R[ $line['G'] ][ $line['C'] ] = $line[ 'M' ];
    
    		if ( !isset( $RC[ $line['C'] ] ) || ( $RC[ $line['C'] ] > $line[ 'M' ]))
    			$RC[ $line['C'] ] = $line[ 'M' ];
    	}
    	$this->saleIntoP = $R;
    	$this->companyIntoP = $RC;
    }
   
}