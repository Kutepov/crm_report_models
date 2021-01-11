<?php
class FouTotalReportForm extends CFormModel
	implements InterfaceReportForm
{
	private $mStart;
	private $mEnd;
	
	private $saleBeforeP = [];
	private $saleIntoP = [];
	private $whiteList = [];

	public $settedMonth;

	public $month;
	public $managers;
	public $managers_backup;
	public $result;
	public $interval_year;
	
	
	////// WTF&
	
    public $monthStart;
    public $monthEnd;
    public $yearStart;
    public $yearEnd;
    public $field;

    public $endDate;
    public $startDate;


    public $accepted;
    public $short;
    public $FK_company;
    public $company_name;

    public $grouping = 10;
    public $piecing1 = 5;
    public $piecing2 = 0;
    public $withSum;
    public $withAlien;
    public $withSeller;

    private $monthLimits;
    private $firstTimes;
    private $dataExtra;

    public $FK_own = 1;
    private $sales;

    /**
     * Declares the validation rules.
     */
    public function rules()
    {
        return array(
			
				
			array('month, managers, interval_year', 'safe'),
		);
    }

    public function attributeLabels()
    {
        return array(        );
    }

    public function getMonths()
    {
    	return Month::getMonthNameList($this->mStart, $this->mEnd, 1);
    }
     
    public function newLongSales( $strict = true )
    {
    	return [];
    }
    
    public function checkAccess($action)
    {
        return Yii::app()->accDispatcher->haveIGotAccess([get_class($this) => "R"]);
    }
    
    public function defaultReport()
    {
    	$this->performReport();
    }
    
    public function prepareParams( &$arrData)
    { 	
    }
    
    /**
     * Получим массив последних продаж до периода И первых продаж за период
     * 
     * В разрезе [Категория + вид ПП][компания] = месяц ПП
     * Любую продажу в периоде можно сравнить с этим, 
     * и определить, новая или нет
     * 
     * @return Ambigous <multitype:multitype: , unknown>
     */
    private function fillFirstTimesTable(  )
    {
    	$DS = $this->getBaseStructureQuery(  $this->mStart - 16, $this->mStart - 2 );
    	$DS->andWhere( 'E.is_long = 1' )->group( 'G, C');
    
      	 
    	$DSin = $this->getBaseStructureQuery(  );   	
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
			($month - $this->saleBeforeP[ $criteria ][ $company ]) > 12
		)
		return true;
				
		return false;
	}
	
	public function isFirstSale( $criteria, $company, $month )
	{
// 		$criteria = $P->FK_sale_product_kind * 1000 + $P->elaboration->FK_feature1;
// 		$company = $P->sale->FK_company;
// 		$month = $P->sale->FK_control_month;

        // есть в периоде и раньше
        if (
            isset($this->saleIntoP[$criteria][$company]) &&
            $this->saleIntoP[$criteria][$company] < $month
        ) {
            return false;
        }

        // это первый в периоде. как до периода?
        if (
            !isset($this->saleBeforeP[$criteria][$company]) ||
            ($month - $this->saleBeforeP[$criteria][$company]) > 12
        ) {
            return true;
        }

        return false;
	}
    
	
	/** 
	 * "белый список" на 2 последних месяца периода
	 * туда идут непроведенные но частично оплаченные
	 * 
	 * потому что для "идеальных предпосылок" прямая фильтрация по этому признаку не сработает ((
	 */
	public function fillSalesWhiteList()
	{
		$DS = $this->getBaseStructureQuery();
		$DS->selectDistinct( 'S.ID' )
			->andWhere( 'S.FK_control_month >= ' . ($this->mEnd - 1))
			->andWhere( ' ((S.W_lock = "Y" ) OR
        		( S.FK_control_month = ' . $this->mEnd . ' AND SMM.payed >= SMM.total * 0.1))');
	
		$this->whiteList = $DS->queryColumn();
	}
	
	private function periodSalesCriteria()
	{
		
		$criteria = new CDbCriteria();
        //$criteria->with = [/*'product'/*, 'elaboration'*/];
        $criteria->join = 'LEFT OUTER JOIN `t_sales_products` `product` ON (`t`.`FK_sale_product`=`product`.`ID`)  LEFT JOIN `t_sales` `sale` ON product.FK_sale = sale.ID ';
        $criteria->addCondition('sale.FK_sale_state != 3');
        $criteria->addCondition('sale.active = 1');
        //$criteria->addCondition('t.active = 1');

        $startMonth = Month::getMonthIndex($this->mStart, 'M');
        $startYear = Month::getMonthIndex($this->mStart, 'Y');

        $endMonth = Month::getMonthIndex($this->mEnd, 'M');
        $endYear = Month::getMonthIndex($this->mEnd, 'Y');

        $criteria->addCondition('
            ( ( month(product.end_date) >= '.$startMonth.' AND year(product.end_date) = '.$startYear.' ) OR ( year(product.end_date) > '.$startYear.' ) )
            AND
            ( ( month(product.end_date) <= '.$endMonth.' AND year(product.end_date) = '.$endYear.' ) OR ( year(product.end_date) < '.$endYear.' ) )
        ');
        
        $criteria->join .= 'LEFT JOIN `t_sales_summaries` `smms` ON `smms`.FK_sale = product.ID';

        $criteria->addCondition('(( sale.W_lock = "Y" ) OR ( sale.FK_control_month >= '. ($this->mEnd - 1) . '))');
        $criteria->distinct = true;
        
        if( count( $this->managers ) && is_array($this->managers))
        {
        	$criteria->addInCondition('sale.FK_manager', $this->managers);
        }
        else 
        {
        	$criteria->addInCondition('sale.FK_manager', $this->managers_backup);
        }
        
        return $criteria;
	}
	
	private function periodLeadsCriteria()
	{
		
		$criteria = new CDbCriteria();
		$criteria->with = ['analitics'];
		$criteria->addCondition('t.active = 1');
		$criteria->addNotInCondition('t.FK_fail_reason', [LeadItemFailureReason::REASON__TEST_SPAM]);
				
		return $criteria;
	}

	
	public function getMonthlyNewSalesMD( $F = 'MD' )
	{
		$criteriaBeforeP = [];
		$criteriaIntoP = [];
		
		//Заполним критерий новизны клиента
		$DS = $this->getBaseStructureQuery( $this->mStart - 16,  $this->mStart - 1);
		$DS->select( 'S.FK_company as C, max( S.FK_control_month) as M')->group('C');
		$rows = $DS->queryAll();
		foreach( $rows as $line )
		{
			$criteriaBeforeP[ $line[ 'C' ]] = $line[ 'M' ];
		}	
		//Заполним критерий новизны клиента2
		$DS = $this->getBaseStructureQuery( );
		$DS->select( 'S.FK_company as C, min( S.FK_control_month) as M')->group('C');
		$rows = $DS->queryAll();
		foreach( $rows as $line )
		{
			$criteriaIntoP[ $line[ 'C' ]] = $line[ 'M' ];
		}
		
		
		$criteria = $this->periodSalesCriteria();
		$criteria->join .= ' LEFT JOIN {{spk_elaborations}} E on E.ID = product.FK_sale_prod_elaboration';
		$criteria->addCondition('E.is_long = 1');
		$dataProvider = new CActiveDataProvider('ServicesProvided', array(
				'criteria'=>$criteria,
		));
		$iterator=new CDataProviderIterator($dataProvider, 500);
		
		$result = [ [],[],[],[] ];
		
		foreach ( $iterator as $product )
		{
			$crit = $product->product->FK_sale_product_kind * 1000 + $product->product->elaboration->FK_feature1;
			$company = $product->product->sale->FK_company;
			$M = $product->product->sale->FK_control_month;
			
			// не первый
			if( !$this->isFirstSaleProduct($product->product)) continue;
						
			// строгий список
			if(( $M < $this->mEnd - 1) || in_array( $product->product->FK_sale, $this->whiteList))
			{
				if( // новый клиент
					!($M > $criteriaIntoP[ $company ]) ||
					(
						isset( $criteriaBeforeP[ $company ]) &&	
						( $M - $criteriaBeforeP[ $company ] > 15)
					)
				)
				{
					if( !isset( $result[0][ $M ])) $result[0][ $M ] = 0;
					{
						if($F=='MD')
							$result[0][ $M ] += $product->product->calcMD(true);
						else
							$result[0][ $M ] += $product->product->calcVD();
					}
				}
				else // старый клиент
				{
					if( !isset( $result[1][ $M ])) $result[1][ $M ] = 0;
					{
						if($F=='MD')
							$result[1][ $M ] += $product->product->calcMD(true);
						else
							$result[1][ $M ] += $product->product->calcVD();
					}
					
				}
				
			}
			
			// расширенный список
			if( $M >= $this->mEnd - 1 && !empty($criteriaIntoP[$company]))
			{
				if( // новый клиент
						$M > $criteriaIntoP[ $company ] ||
						(
								isset( $criteriaBeforeP[ $company ]) &&
								( $M - $criteriaBeforeP[ $company ] > 15)
						)
				)
				{
					if( !isset( $result[2][ $M ])) $result[2][ $M ] = 0;
					{
						if($F=='MD')
							$result[2][ $M ] += $product->product->calcMD(true);
						else
							$result[2][ $M ] += $product->product->calcVD();
						
					}
				}
				else // старый клиент
				{
					if( !isset( $result[3][ $M ])) $result[3][ $M ] = 0;
					{
						if($F=='MD')
							$result[3][ $M ] += $product->product->calcMD(true);
						else
							$result[3][ $M ] += $product->product->calcVD();
						
					}
					
				}
				
				
			}
			
		}
		
		return $result;
	}
	
	public function getMonthlyNewSalesMidi($byCompanies = false)
	{
				
		$criteria = $this->periodSalesCriteria();


        $criteria->join .= ' LEFT JOIN {{spk_elaborations}} E on E.ID = product.FK_sale_prod_elaboration';
        $criteria->addCondition('E.is_long = 1');
		$dataProvider = new CActiveDataProvider('ServicesProvided', array(
				'criteria'=>$criteria,
		));
		$iterator=new CDataProviderIterator($dataProvider, 500);
	
		$result = [ [],[],[],[] ];
	
		foreach ( $iterator as $product )
		{
			$crit = $product->product->FK_sale_product_kind * 1000 + $product->product->elaboration->FK_feature1;
			$company = $product->product->sale->FK_company;
			$M = $product->product->sale->FK_control_month;
				
			// не первый
			if( !$this->isFirstSaleProduct($product->product)) continue;
	
			// строгий список
			if(( $M < $this->mEnd - 1) || in_array( $product->product->FK_sale, $this->whiteList))
			{
				
				if( !isset( $result[0][ $M ][$crit][$company]))
					$result[0][ $M ][$crit][$company] = 0;
				$result[0][ $M ][$crit][$company] +=  $product->product->calcMD(true);
				
				
				if( !isset( $result[1][ $M ][$crit][$company]))
					$result[1][ $M ][$crit][$company] = 0;
				$result[1][ $M ][$crit][$company] +=  $product->product->calcVD();
				
	
			}
				
			// расширенный список
			if( $M >= $this->mEnd - 1)
			{
				if( !isset( $result[2][ $M ][$crit][$company]))
					$result[2][ $M ][$crit][$company] = 0;
				$result[2][ $M ][$crit][$company] +=  $product->product->calcMD(true);
				
				
				if( !isset( $result[3][ $M ][$crit][$company]))
					$result[3][ $M ][$crit][$company] = 0;
				$result[3][ $M ][$crit][$company] +=  $product->product->calcVD();
			}
				
		}
	
		$_res = [[],[],[],[]];
		foreach( $result as $key => $midiblock )
		{
			foreach ($midiblock as $M => $data)
			{
				$S = 0;
				$N = 0;
				$companies = [];
				foreach ($data as $crit => $critData )
					foreach ($critData as $company => $value )
					{
						$S += $value;
						$N ++;
						$companies[] = $company;
					}
				$companies = array_unique($companies);
                if ($byCompanies)
                    $_res[ $key ][ $M ] = ( $N ) ? ($S/count($companies)) : 0;
                else
                    $_res[ $key ][ $M ] = ( $N ) ? ($S/$N) : 0;
			}
		}
		
		
		return $_res;
	}
	
	/**
	 * новые длинные продажи помесячно
	 * @return multitype:multitype:number
	 */
	public function getFirstSalesNumber()
	{
		$R = [];
		$RX = [];
		
		$criteria = $this->periodSalesCriteria();
		$criteria->addCondition('elaboration.is_long = 1');
		$dataProvider = new CActiveDataProvider('SaleProduct', array(
				'criteria'=>$criteria,
		));	
		$iterator=new CDataProviderIterator($dataProvider, 500);
		
		foreach ( $iterator as $product )
		{
			$crit = $product->FK_sale_product_kind * 1000 + $product->elaboration->FK_feature1;
			$company = $product->sale->FK_company;
			
			// не первый
			if( !$this->isFirstSaleProduct($product)) continue;
			
			$M = $product->sale->FK_control_month;
			if( $M >= $this->mEnd - 1) // за последние 2 месяца!
			{
				// 1. расширенный список
				$RX[ $M ][$crit][$company] = 1;
				
				// 2. белый список
				if( in_array( $product->FK_sale, $this->whiteList))
				{
					$R[ $M ][$crit][$company] = 1;
				}
			}
			else 
			{
				$R[ $M ][$crit][$company] = 1;
			}
		}
		
		$return = [];
		foreach ( $R  as  $M=>$RMdata)
		{
			$T = 0;
			foreach ($RMdata as $RMC=>$RMarr)
				$T += count( $RMarr );

			$return [$M] = $T;
		}
		
		$return_x = [];
		foreach ( $RX as  $M=>$RMdata)
		{
			$T = 0;
			foreach ($RMdata as $RMC=>$RMarr)
				$T += count( $RMarr );
		
			$return_x [$M] = $T;
		}
		
		return  [
			$return,
			$return_x	
		];
	}
    
	
	
	private function _getWeeklyPeriods( $weeks)
	{
		$mond = new DateTime( );
		$mond->modify('monday this week');
		$T = $mond->getTimestamp();
		$periods = [];

		for( $i = $weeks; $i>=0; $i-- )
		{
			$periods[] = $T - $i * 7 * 24* 3600;
		}

		return $periods;
	}

    private function _getMonthlyPeriods($months)
    {
        $from = new DateTime(date('Y-m-01'));
        $periods = [];

        for ($i = $months; $i >= 0; $i--) {
            $periods[] = $from->getTimestamp();
            $from->modify('-1 month');
        }

        return array_reverse($periods);
    }
	
	
	public function getNewLongSalesWeekly()
	{
		$periods = $this->_getWeeklyPeriods(12);
			
		$R = [];
		$RX = []; 
		
		$criteria = $this->periodSalesCriteria();
		$criteria->addCondition('elaboration.is_long = 1');
		$criteria->addCondition( 'sale.sale_date >= "' . date( "Y-m-d H:i:s", $periods[0]) . '"' );
		
		$dataProvider = new CActiveDataProvider('SaleProduct', array(
				'criteria'=>$criteria,
		));
		$iterator=new CDataProviderIterator($dataProvider, 500);
		
		foreach ( $iterator as $product )
		{
			// не первый
			if( !$this->isFirstSaleProduct($product)) continue;
			
			$crit = $product->FK_sale_product_kind * 1000 + $product->elaboration->FK_feature1;
			$company = $product->sale->FK_company;

			$week_key = 0;
			$sale_time = strtotime( $product->sale->sale_date);
			
			while( isset( $periods[ $week_key + 1]) && $sale_time > $periods[ $week_key + 1 ])
				$week_key ++;
			
						
			$M = $product->sale->FK_control_month;
			if( $M >= $this->mEnd - 1) // за последние 2 месяца!
			{
				// 1. расширенный список
				$RX[ $week_key ][$crit][$company] = 1;
		
				// 2. белый список
				if( in_array( $product->FK_sale, $this->whiteList))
				{
					$R[ $week_key ][$crit][$company] = 1;
				}
			}
			else
			{
				$R[ $week_key ][$crit][$company] = 1;
			}
		}
		
		$return = [];
		foreach ( $R  as  $M=>$RMdata)
		{
			$T = 0;
			foreach ($RMdata as $RMC=>$RMarr)
				$T += count( $RMarr );
		
			$return [$M] = $T;
		}
		
		$return_x = [];
		foreach ( $RX as  $M=>$RMdata)
		{
			$T = 0;
			foreach ($RMdata as $RMC=>$RMarr)
				$T += count( $RMarr );
		
			$return_x [$M] = $T;
		}
		
		return [
			'labels' => $periods,
			'data'	=>	$return,
			'data_x'	=> $return_x
		];
	}
	
	public function getLeadsWeekly()
	{
		$periods = $this->_getWeeklyPeriods(23);
		
		
		$l_site = [];
		$l_tm = [];
		$l_sh = [];
		$l_other = [];
		$l_call = [];
		$l_email = [];
		$l_webform = [];
		$l_recommendation = [];
		$l_advertising = [];
		$l_old_client = [];
        $l_current_client = [];
		$l_web = [];
		$l_tender = [];
		$l_generator = [];
		$l_chat_iq = [];
        $l_store = [];
        $l_callback = [];
        $l_vk = [];
        $l_online_chat = [];
        $l_face_tracker = [];
        $l_b24_app = [];
        $l_iq_dev_digital = [];
        $l_runet = [];
        $l_ruward = [];

        $ch = curl_init('https://api.iqcrm.net/?ajax_action=get_leads&dateStart=' . date("Y-m-d", $periods[0]));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        $iterator = json_decode(curl_exec($ch));
        curl_close($ch);

        if (!empty($iterator->result))
            foreach ($iterator->result as $lead)
            {
                $lead_time = strtotime($lead->DATE_CREATE);
                $week_key = 0;
                while(isset($periods[$week_key + 1]) && $lead_time > $periods[$week_key + 1])
                    $week_key ++;

                    if(isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 4)
                    {
                        if(!isset($l_site[$week_key])) $l_site[$week_key] = 0;
                        $l_site[$week_key] ++ ;
                    }
                    elseif(isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 1)
                    {
                        if(!isset($l_tm[$week_key])) $l_tm[$week_key] = 0;
                        $l_tm[$week_key] ++ ;
                    }
                    elseif(isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'TRADE_SHOW')
                    {
                        if(!isset($l_sh[$week_key])) $l_sh[$week_key] = 0;
                        $l_sh[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'CALL')
                    {
                        if(!isset($l_call[$week_key])) $l_call[$week_key] = 0;
                        $l_call[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'EMAIL')
                    {
                        if(!isset($l_email[$week_key])) $l_email[$week_key] = 0;
                        $l_email[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'WEBFORM')
                    {
                        if(!isset($l_webform[$week_key])) $l_webform[$week_key] = 0;
                        $l_webform[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'WEB')
                    {
                        if(!isset($l_web[$week_key])) $l_web[$week_key] = 0;
                        $l_web[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'RECOMMENDATION')
                    {
                        if(!isset($l_recommendation[$week_key])) $l_recommendation[$week_key] = 0;
                        $l_recommendation[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'ADVERTISING')
                    {
                        if(!isset($l_advertising[$week_key])) $l_advertising[$week_key] = 0;
                        $l_advertising[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 2)
                    {
                        if(!isset($l_old_client[$week_key])) $l_old_client[$week_key] = 0;
                        $l_old_client[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 3)
                    {
                        if(!isset($l_current_client[$week_key])) $l_current_client[$week_key] = 0;
                        $l_current_client[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'PARTNER')
                    {
                        if(!isset($l_tender[$week_key])) $l_tender[$week_key] = 0;
                        $l_tender[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'RC_GENERATOR')
                    {
                        if(!isset($l_generator[$week_key])) $l_generator[$week_key] = 0;
                        $l_generator[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == '2|YANDEX')
                    {
                        if(!isset($l_chat_iq[$week_key])) $l_chat_iq[$week_key] = 0;
                        $l_chat_iq[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'STORE')
                    {
                        if(!isset($l_store[$week_key])) $l_store[$week_key] = 0;
                        $l_store[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'CALLBACK')
                    {
                        if(!isset($l_callback[$week_key])) $l_callback[$week_key] = 0;
                        $l_callback[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == '2|VK')
                    {
                        if(!isset($l_vk[$week_key])) $l_vk[$week_key] = 0;
                        $l_vk[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == '2|OPENLINE')
                    {
                        if(!isset($l_online_chat[$week_key])) $l_online_chat[$week_key] = 0;
                        $l_online_chat[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'FACE_TRACKER')
                    {
                        if(!isset($l_face_tracker[$week_key])) $l_face_tracker[$week_key] = 0;
                        $l_face_tracker[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'B24_APPLICATION')
                    {
                        if(!isset($l_b24_app[$week_key])) $l_b24_app[$week_key] = 0;
                        $l_b24_app[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 5)
                    {
                        if(!isset($l_iq_dev_digital[$week_key])) $l_iq_dev_digital[$week_key] = 0;
                        $l_iq_dev_digital[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 6)
                    {
                        if(!isset($l_runet[$week_key])) $l_runet[$week_key] = 0;
                        $l_runet[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 7)
                    {
                        if(!isset($l_ruward[$week_key])) $l_ruward[$week_key] = 0;
                        $l_ruward[$week_key] ++ ;
                    }
                    elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'OTHER')
                    {
                        if(!isset($l_other[$week_key])) $l_other[$week_key] = 0;
                        $l_other[$week_key] ++ ;
                    }
                    else
                    {
                        if(!isset($l_other[$week_key])) $l_other[$week_key] = 0;
                        $l_other[$week_key] ++ ;
                    }
            }

				
		return [
				'labels'	=> $periods,
				'site'		=> $l_site,
				'telem'		=> $l_tm,
                'other'		=> $l_other,
				'show'		=> $l_sh,
                'call'    => $l_call,
                'email'   => $l_email,
                'webform' => $l_webform,
                'web'     => $l_web,
                'tender'  => $l_tender,
                'chat_iq' => $l_chat_iq,
                'store'   => $l_store,
                'callback'=> $l_callback,
                'vk'      => $l_vk,
                'b24_app' => $l_b24_app,
                'runet'   => $l_runet,
                'ruward'  => $l_ruward,
                'generator'      => $l_generator,
                'face_tracker'   => $l_face_tracker,
                'online_chat'    => $l_online_chat,
                'old_client'     => $l_old_client,
                'current_client' => $l_current_client,
                'advertising'    => $l_advertising,
                'recommendation' => $l_recommendation,
                'iq_dev_digital' => $l_iq_dev_digital,
		];
	}

    public function getLeadsMonthly() {
        $periods = $this->_getMonthlyPeriods(6);


        $l_site = [];
        $l_tm = [];
        $l_sh = [];
        $l_other = [];
        $l_call = [];
        $l_email = [];
        $l_webform = [];
        $l_recommendation = [];
        $l_advertising = [];
        $l_old_client = [];
        $l_current_client = [];
        $l_web = [];
        $l_tender = [];
        $l_generator = [];
        $l_chat_iq = [];
        $l_store = [];
        $l_callback = [];
        $l_vk = [];
        $l_online_chat = [];
        $l_face_tracker = [];
        $l_b24_app = [];
        $l_iq_dev_digital = [];
        $l_runet = [];
        $l_ruward = [];

        $ch = curl_init('https://api.iqcrm.net/?ajax_action=get_leads&dateStart=' . date("Y-m-d", $periods[0]));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        $iterator = json_decode(curl_exec($ch));
        curl_close($ch);

        if (!empty($iterator->result))
            foreach ($iterator->result as $lead) {
                $lead_time = strtotime($lead->DATE_CREATE);
                $month_key = 0;
                while (isset($periods[$month_key + 1]) && $lead_time > $periods[$month_key + 1]) {
                    $month_key++;
                }

                if(isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 4)
                {
                    if(!isset($l_site[$month_key])) $l_site[$month_key] = 0;
                    $l_site[$month_key] ++ ;
                }
                elseif(isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 1)
                {
                    if(!isset($l_tm[$month_key])) $l_tm[$month_key] = 0;
                    $l_tm[$month_key] ++ ;
                }
                elseif(isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'TRADE_SHOW')
                {
                    if(!isset($l_sh[$month_key])) $l_sh[$month_key] = 0;
                    $l_sh[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'CALL')
                {
                    if(!isset($l_call[$month_key])) $l_call[$month_key] = 0;
                    $l_call[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'EMAIL')
                {
                    if(!isset($l_email[$month_key])) $l_email[$month_key] = 0;
                    $l_email[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'WEBFORM')
                {
                    if(!isset($l_webform[$month_key])) $l_webform[$month_key] = 0;
                    $l_webform[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'WEB')
                {
                    if(!isset($l_web[$month_key])) $l_web[$month_key] = 0;
                    $l_web[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'RECOMMENDATION')
                {
                    if(!isset($l_recommendation[$month_key])) $l_recommendation[$month_key] = 0;
                    $l_recommendation[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'ADVERTISING')
                {
                    if(!isset($l_advertising[$month_key])) $l_advertising[$month_key] = 0;
                    $l_advertising[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 2)
                {
                    if(!isset($l_old_client[$month_key])) $l_old_client[$month_key] = 0;
                    $l_old_client[$week_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 3)
                {
                    if(!isset($l_current_client[$month_key])) $l_current_client[$month_key] = 0;
                    $l_current_client[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'PARTNER')
                {
                    if(!isset($l_tender[$month_key])) $l_tender[$month_key] = 0;
                    $l_tender[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'RC_GENERATOR')
                {
                    if(!isset($l_generator[$month_key])) $l_generator[$month_key] = 0;
                    $l_generator[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == '2|YANDEX')
                {
                    if(!isset($l_chat_iq[$month_key])) $l_chat_iq[$month_key] = 0;
                    $l_chat_iq[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'STORE')
                {
                    if(!isset($l_store[$month_key])) $l_store[$month_key] = 0;
                    $l_store[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'CALLBACK')
                {
                    if(!isset($l_callback[$month_key])) $l_callback[$month_key] = 0;
                    $l_callback[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == '2|VK')
                {
                    if(!isset($l_vk[$month_key])) $l_vk[$month_key] = 0;
                    $l_vk[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == '2|OPENLINE')
                {
                    if(!isset($l_online_chat[$month_key])) $l_online_chat[$month_key] = 0;
                    $l_online_chat[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'FACE_TRACKER')
                {
                    if(!isset($l_face_tracker[$month_key])) $l_face_tracker[$month_key] = 0;
                    $l_face_tracker[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'B24_APPLICATION')
                {
                    if(!isset($l_b24_app[$month_key])) $l_b24_app[$month_key] = 0;
                    $l_b24_app[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 5)
                {
                    if(!isset($l_iq_dev_digital[$month_key])) $l_iq_dev_digital[$month_key] = 0;
                    $l_iq_dev_digital[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 6)
                {
                    if(!isset($l_runet[$month_key])) $l_runet[$month_key] = 0;
                    $l_runet[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 7)
                {
                    if(!isset($l_ruward[$month_key])) $l_ruward[$month_key] = 0;
                    $l_ruward[$month_key] ++ ;
                }
                elseif (isset($lead->SOURCE_ID) && $lead->SOURCE_ID == 'OTHER')
                {
                    if(!isset($l_other[$month_key])) $l_other[$month_key] = 0;
                    $l_other[$month_key] ++ ;
                }
                else
                {
                    if(!isset($l_other[$month_key])) $l_other[$month_key] = 0;
                    $l_other[$month_key] ++ ;
                }
            }


        return [
            'labels'	=> $periods,
            'site'		=> $l_site,
            'telem'		=> $l_tm,
            'other'		=> $l_other,
            'show'		=> $l_sh,
            'call'    => $l_call,
            'email'   => $l_email,
            'webform' => $l_webform,
            'web'     => $l_web,
            'tender'  => $l_tender,
            'chat_iq' => $l_chat_iq,
            'store'   => $l_store,
            'callback'=> $l_callback,
            'vk'      => $l_vk,
            'b24_app' => $l_b24_app,
            'runet'   => $l_runet,
            'ruward'  => $l_ruward,
            'generator'      => $l_generator,
            'face_tracker'   => $l_face_tracker,
            'online_chat'    => $l_online_chat,
            'old_client'     => $l_old_client,
            'current_client' => $l_current_client,
            'advertising'    => $l_advertising,
            'recommendation' => $l_recommendation,
            'iq_dev_digital' => $l_iq_dev_digital,
        ];
    }
	
	
	/**
	 * Всего длинных продаж в месяц
	 * @param string $strict
	 * @return multitype:number
	 */
	public function  getLongSalesNumber( $strict = true )
    {
    	$DS = $this->getBaseStructureQuery();
    	$DS = $this->filterManagers( $DS);
    	$DS->selectDistinct( '
    			(P.FK_sale_product_kind * 1000 + E.FK_feature1) AS CR, 
    			S.FK_company as C,
    			S.FK_control_month as M
    		')
    		->andWhere('E.is_long = 1');
    	
    	if( $strict )
			$this->strict( $DS );
    	else 
    		$DS->andWhere( 'S.FK_control_month >=  (' . ($this->mEnd - 1) . ')' );
    	
    	$R = [];
    	    	
    	$rows = $DS->queryAll();
    	foreach ($rows as $line)
    	{
    		if( !isset( $R[ $line[ 'M' ]]))
    			$R[ $line[ 'M' ]] = 0;
    		$R[ $line[ 'M' ]] ++;
    	}	
    	
    	return $R;
    }
    
     
    public function  getMonthlySalesOfKind($condition, $strict = true, $typeManager = '')
    {
    	$DS = $this->getBaseStructureQuery();
    	$DS = $this->filterManagers( $DS);
        $DS->andWhere($condition);
        if ($typeManager == 'mpp') {
            $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList( CrmAccessDispatcher::GROUP_SALEMGR );
            $mpp_list = implode(', ', $mpp_list);
            $DS->andWhere('S.FK_manager in ('.$mpp_list.')');
        }
        if ($typeManager == 'accm') {
            $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList( CrmAccessDispatcher::GROUP_ACC_MGR);
            $mpp_list = implode(', ', $mpp_list);
            $DS->andWhere('S.FK_manager in ('.$mpp_list.')');
        }

        if ($strict)
            $this->strict($DS);
        else
            $DS->andWhere( 'S.FK_control_month >=  (' . ($this->mEnd - 1) . ')' );

        $DEBUG = clone($DS);

        $DS->selectDistinct( "
    			sum({$this->getField('vd')}) as S,
    			S.FK_control_month as M")
            ->andWhere($condition)
            ->group('M');

        $DEBUG->select( "
    			{$this->getField('vd')} as S,
    			S.FK_control_month as M,
    			S.FK_manager as perf, 
    			S.FK_company as cmp, 
    			S.ID as sid,
    			P.ID as pid    			
    		")
            ->order('M, S DESC, cmp');

    	$R = [];
    	$dbg = [];
    
    	$rows = $DS->queryAll();
    	foreach ($rows as $line)
    	{
            if (!isset($R[$line['M']])) {
                $R[$line['M']] = $line['S'];
            }
    	}

        $rows = $DEBUG->queryAll();
        foreach ($rows as $line)
        {
            if ($line['S']) {
                if (!isset($dbg[$line['M']])) {
                    $dbg[$line['M']] = [];
                }
                if (!isset($dbg[$line['M']][$line['cmp']])) {
                    $dbg[$line['M']][$line['cmp']] = [];
                }
                if (!isset($dbg[$line['M']][$line['cmp']][$line['sid']])) {
                    $dbg[$line['M']][$line['cmp']][$line['sid']] = [];
                }
                $dbg[$line['M']][$line['cmp']][$line['sid']][] = [
                    'pid' => $line['pid'],
                    'prf' => User::getOneManagerFio($line['perf']),
                    's' => round($line['S'],2),
                ];
            }
        }
    	 
    	return ['data' => $R, 'dbg' => $dbg];
    }
    
    public function  getMonthlySalesOfKindF($condition, $firsts = true)
    { 	
    	$DS = $this->getBaseStructureQuery();
    	$DS = $this->filterManagers($DS);
    	$DS->selectDistinct( "
    			    			
    			sum({$this->getField('vd')}) as S,
    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
				S.FK_company as company,
    			S.FK_control_month as M
    			")
    			->andWhere($condition)
    			->group('M, criteria, company');
    
    	$this->strict( $DS );
    	$rows = $DS->queryAll();
    	
    	$R = [];
    
    
    	
    	foreach ($rows as $line)
    	{
    		if( !isset( $R[ $line[ 'M' ]]))
    			$R[ $line[ 'M' ]]  = 0; 
    		
    		if( $firsts == $this->isFirstSale($line['criteria'], $line['company'], $line['M']))
    			$R[ $line[ 'M' ]] += $line[ 'S' ];
    	}
    
    	return $R;
    }
	
    public function getMonthlySalesCity($newAndLongOnly = false, $nodev = false)
    {
    	$MD = [];
    	$VD = [];
    	$City = [];

    	$criteria = $this->periodSalesCriteria();
        $criteria->mergeWith(['join' => 'LEFT JOIN {{spk_elaborations}} elaboration ON elaboration.ID = product.FK_sale_prod_elaboration']);
    	if ($newAndLongOnly)
    		$criteria->addCondition('elaboration.is_long = 1');

        if ($nodev) {
            $criteria->mergeWith(['join' => 'LEFT JOIN {{managers}} Prf ON product.FK_performer = Prf.ID']);
            $criteria->addCondition('NOT(Prf.FK_department = 6 AND (product.FK_sale_product_kind IN (10, 16, 19, 30, 67) OR elaboration.ID = 185))');
        }
    	
    	$dataProvider = new CActiveDataProvider('ServicesProvided', array(
    			'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 500);
    	 
    	foreach ( $iterator as $product )
    	{
    		//$M = $product->product->sale->FK_control_month;
            $M = Month::getQueryID((int) explode('-', $product->product->end_date)[1], (int) explode('-', $product->product->end_date)[0]);
    		
    		// не первый
    		if( $newAndLongOnly && !$this->isFirstSaleProduct($product->product)) continue;
    		
    		// строгий список
    		//if(( $M < $this->mEnd - 1) || in_array( $product->product->FK_sale, $this->whiteList))
    		//{
    		
	    	
	    		$C = $product->product->sale->company->FK_town;
	    		
	    		if (!isset( $MD[ $C ][ $M ] ))
	    		{
	    			$MD[ $C ][ $M ] = 0;
	    			$VD[ $C ][ $M ] = 0;
	    			$City[ $C ] = 1;
	    		}
	
//	    		$MD[ $C ][ $M ] += $product->calcMD(1);
	    		$VD[ $C ][ $M ] += $product->product->calcVD();
    		
    		//}
    	}
    	
    	return [ $City, $MD, $VD];
    	
    }
    
    
    public function getMonthlySalesVal($nodev = false)
    {

    	$R = [];
    	$RX = [];
    	
    	$criteria = $this->periodSalesCriteria();

        if ($nodev) {
            $criteria->mergeWith(['join' => 'LEFT JOIN {{managers}} Prf ON product.FK_performer = Prf.ID']);
            $criteria->mergeWith(['join' => 'LEFT JOIN {{spk_elaborations}} elaboration ON elaboration.ID = product.FK_sale_prod_elaboration']);
            $criteria->addCondition('NOT(Prf.FK_department = 6 AND (product.FK_sale_product_kind IN (10, 16, 19, 30, 67) OR elaboration.ID = 185))');
        }
    	$dataProvider = new CActiveDataProvider('ServicesProvided', array(
    			'criteria'=>$criteria,
    	));
    	$iterator = new CDataProviderIterator($dataProvider, 500);
    	
    	foreach ( $iterator as $product )
    	{
    		
    		//$M = $product->product->sale->FK_control_month;
            $M = Month::getQueryID((int) explode('-', $product->product->end_date)[1], (int) explode('-', $product->product->end_date)[0]);
    		
     		/*if( $M >= $this->mEnd - 1) // за последние 2 месяца!
    		{
    			// 1. расширенный список
    			if( !isset( $RX[$M]) ) $RX[$M] = 0;
    			$RX[$M] += $product->product->calcVD();
    	
    			// 2. белый список
    			if( in_array( $product->product->FK_sale, $this->whiteList))
    			{
    				if( !isset( $R[$M]) ) $R[$M] = 0;
    				$R[$M] += $product->product->calcVD();
    			}
    		}
    		else
    		{*/
    			if( !isset( $R[$M]) ) $R[$M] = 0;
    			$R[$M] += $product->product->calcVD();
    		//}
    	}
    	
    	return [ $R, $RX ];
    }
    
    
   
    
    
    
    public function getMonthlyNewSalesVal()
    {
    
    	$R = [];
    	$RX = [];
    	 
    	$criteria = $this->periodSalesCriteria();
    	$criteria->addCondition('elaboration.is_long = 1');
    	$dataProvider = new CActiveDataProvider('SaleProduct', array(
    			'criteria'=>$criteria,
    	));
    	$iterator=new CDataProviderIterator($dataProvider, 500);
    	 
    	foreach ( $iterator as $product )
    	{
    		if( !$this->isFirstSaleProduct($product)) continue;
    		
    		$M = $product->sale->FK_control_month;
    
    		if( $M >= $this->mEnd - 1) // за последние 2 месяца!
    		{
    			// 1. расширенный список
    			if( !isset( $RX[$M]) ) $RX[$M] = 0;
    			$RX[$M] += $product->calcVD();
    			 
    			// 2. белый список
    			if( in_array( $product->FK_sale, $this->whiteList))
    			{
    				if( !isset( $R[$M]) ) $R[$M] = 0;
    				$R[$M] += $product->calcVD();
    			}
    		}
    		else
    		{
    			if( !isset( $R[$M]) ) $R[$M] = 0;
    			$R[$M] += $product->calcVD();
    		}
    	}
    	 
    	return [ $R, $RX ];
    }
    
    
    public function getMonthlyStructure_LongShort( $value, $strict = true, $nodev = false)
    {

        $criteria = $this->periodSalesCriteria();

        if ($nodev) {
            $criteria->mergeWith(['join' => 'LEFT JOIN {{managers}} Prf ON product.FK_performer = Prf.ID']);
            $criteria->mergeWith(['join' => 'LEFT JOIN {{spk_elaborations}} elaboration ON elaboration.ID = product.FK_sale_prod_elaboration']);
            $criteria->addCondition('NOT(Prf.FK_department = 6 AND (product.FK_sale_product_kind IN (10, 16, 19, 30, 67) OR elaboration.ID = 185))');
        }

        $dataProvider = new CActiveDataProvider('ServicesProvided', array(
            'criteria'=>$criteria,
        ));
        $iterator=new CDataProviderIterator($dataProvider, 5000);

        $long = [];
        $short = [];
        $all = [];

        foreach ( $iterator as $product )
        {
            //$M = $product->sale->FK_control_month;
            $M = Month::getQueryID((int) explode('-', $product->product->end_date)[1], (int) explode('-', $product->product->end_date)[0]);

            if (!isset($long[$M])) $long[$M] = 0;
            if (!isset($short[$M])) $short[$M] = 0;

            if ($product->product->elaboration->is_long && !$product->product->is_unique) $temp = &$long[$M];
            else $temp = &$short[$M];

            /*if( $M >= $this->mEnd - 1) // за последние 2 месяца!
            {
                // 1. расширенный список
                if (!isset($all[$M])) $all[$M] = 0;
                $all[$M] += $product->product->calcVD();

                // 2. белый список
                if( in_array( $product->product->FK_sale, $this->whiteList))
                {
                    $temp += $product->product->calcVD();
                }
            }
            else*/
                $temp += $product->product->calcVD();
        }

        return [
            0 => $short,
            1 => $long,
            2 => $all
        ];

        /*$DS = $this->getBaseStructureQuery();
        $DS = $this->filterManagers($DS);
        $DS->select($this->getField($value) . ' AS S, 
    			S.FK_control_month AS M, 
    			E.is_long AS L, 
    			P.is_unique AS LU');
//            ->group('M, L, LU');

        if ($nodev) {
            $DS->andWhere('NOT(Prf.FK_department = 6 AND (P.FK_sale_product_kind IN (10, 16) OR E.ID = 185))');
        }

        if ($strict)
            $this->strict($DS);
        else
            $DS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');

        $long = [];
        $short = [];

        $rows = $DS->queryAll();
        foreach ($rows as $line) {
            if ($line['L'] && !$line['LU']) {
                if (empty($long[$line['M']])) $long[$line['M']] = 0;
                $long[$line['M']] += $line['S'];
            } else {
                if (empty($short[$line['M']])) $short[$line['M']] = 0;
                $short[$line['M']] += $line['S'];
            }
        }

        return [
            0 => $short,
            1 => $long
        ];*/
    }

    //FIXME: Этот метод считает неверно, он больше не используется, но пусть пока останется тут
    public function getMonthlyStructure_LongShortROP($value, $strict = true)
    {
        $DS = $this->getBaseStructureQuery();
        $DS = $this->filterManagers($DS);

        $DS->select($this->getField($value) . ' AS S, 
    			S.FK_control_month AS M,
    			S.ID as SID,
    			S.name as SNM, 
    			E.is_long AS L, 
    			P.is_unique AS LU,
    			E.ID as EID,
    			P.FK_sale_product_kind as PRK,
    			Prf.FK_department as DPT,
    			SK.FK_category as CTG,
                P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
                C.ID as company'
        )
        ->order('M');
//            ->group('M, L, LU, PRK, CTG, EID, DPT');

        if ($strict)
            $this->strict($DS);
        else
            $DS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');

        $rows = $DS->queryAll();

        $long = ['SEO' => [], 'ADV' => [], 'CRO' => [], 'OTH' => [], 'SITE' => [], 'DBG' => []];
        $short = ['SITE' => [], 'OTH' => [], 'DOM' => [], 'HOST' => [], 'CRO' => [], 'DBG' => []];

        $longDBG = ['SEO' => [], 'ADV' => [], 'CRO' => [], 'OTH' => [], 'SITE' => []];
        $shortDBG = ['SITE' => [], 'DOM' => [], 'HOST' => [], 'OTH' => []];

        $M = $this->getMonths();

        $firstVD = [];

        foreach ($rows as $line) {

            if (!isset($long['SEO'][$line['M']])) {$long['SEO'][$line['M']] = 0;}
            if (!isset($long['CRO'][$line['M']])) {$long['CRO'][$line['M']] = 0;}
            if (!isset($long['ADV'][$line['M']])) {$long['ADV'][$line['M']] = 0;}
            if (!isset($long['OTH'][$line['M']])) {$long['OTH'][$line['M']] = 0;}
            if (!isset($long['SITE'][$line['M']])) {$long['SITE'][$line['M']] = 0;}
            if (!isset($long['DBG'][$line['M']])) {$long['DBG'][$line['M']] = 0;}
            if (!isset($short['SITE'][$line['M']])) {$short['SITE'][$line['M']] = 0;}
            if (!isset($short['DOM'][$line['M']])) {$short['DOM'][$line['M']] = 0;}
            if (!isset($short['HOST'][$line['M']])) {$short['HOST'][$line['M']] = 0;}
            if (!isset($short['OTH'][$line['M']])) {$short['OTH'][$line['M']] = 0;}
            if (!isset($short['CRO'][$line['M']])) {$short['CRO'][$line['M']] = 0;}
            if (!isset($short['DBG'][$line['M']])) {$short['DBG'][$line['M']] = 0;}

            if ($line['L'] && !$line['LU']) {

                if ($this->isFirstSale($line['criteria'], $line['company'], $line['M'])) {
                    if (!isset($firstVD[$line['M']][$line['company']]))
                        $firstVD[$line['M']][$line['company']] = 0;

                    $firstVD[$line['M']][$line['company']] += $line['S'];

                }

                if ($line['EID'] != 226 && $line['PRK'] == 13) {

                    $long['SEO'][$line['M']] += $line['S'];

                    $longDBG['SEO'][$M[$line['M']]][] = $this->getDBGAr($line);

                } elseif ($line['PRK'] == 66) {

                    $long['CRO'][$line['M']] += $line['S'];

                    $longDBG['CRO'][$M[$line['M']]][] = $this->getDBGAr($line);

                } elseif ($line['CTG'] == 1) {

                    $long['ADV'][$line['M']] += $line['S'];

                    $longDBG['ADV'][$M[$line['M']]][] = $this->getDBGAr($line);
                }
                elseif (in_array($line['PRK'], [10, 16, 67]) || $line['EID'] == 185) {

                    $long['SITE'][$line['M']] += $line['S'];

                    $longDBG['SITE'][$M[$line['M']]][] = $this->getDBGAr($line);
                } else {

                    $long['OTH'][$line['M']] += $line['S'];

                    $longDBG['OTH'][$M[$line['M']]][] = $this->getDBGAr($line);
                }

            } else {

                if (in_array($line['PRK'], [10, 16, 67]) || $line['EID'] == 185) {
                    $short['SITE'][$line['M']] += $line['S'];

                    $shortDBG['SITE'][$M[$line['M']]][] = $this->getDBGAr($line);
                }
                elseif ($line['PRK'] == 17) {
                    $short['DOM'][$line['M']] += $line['S'];

                    $shortDBG['DOM'][$M[$line['M']]][] = $this->getDBGAr($line);
                } elseif ($line['PRK'] == 19) {
                    $short['HOST'][$line['M']] += $line['S'];

                    $shortDBG['HOST'][$M[$line['M']]][] = $this->getDBGAr($line);
                } elseif ($line['PRK'] == 66) {
                    $short['CRO'][$line['M']] += $line['S'];

                    $shortDBG['CRO'][$M[$line['M']]][] = $this->getDBGAr($line);
                }
                else {
                    $short['OTH'][$line['M']] += $line['S'];

                    $shortDBG['OTH'][$M[$line['M']]][] = $this->getDBGAr($line);
                }

            }
        }

        $field = $this->getField($value);
        $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList( CrmAccessDispatcher::GROUP_SALEMGR );

        //добавляем Чумак!
        if (!in_array(11, $mpp_list)) {
            $mpp_list[] = 11;
        }

        $DS2 = $this->getBaseStructureQuery( $this->mStart - 2, $this->mEnd);
        $DS2->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
        $this->filterMarkedShorts($DS2, false);
        $DS2->selectDistinct( "
    			
    			sum($field) as S,

    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
    			S.FK_company as C,
    			S.FK_control_month as M,
				S.FK_manager as U
    			")
            //->andWhere($condition)
            ->group('M, criteria, C, U');

        if ($strict) {
            $this->strict($DS2);
        } else {
            $DS2->andWhere('S.FK_control_month >= (' . ($this->mEnd - 1) . ')');
        }
        $rows2 = $DS2->queryAll();

        $R = [];
        foreach ($rows2 as $line)
        {
            if( !isset( $R[ $line[ 'C' ]])) $R[ $line[ 'C' ]]  = [];
            if( !isset( $R[ $line[ 'C' ]][ $line['M']] )) $R[ $line[ 'C' ]][ $line['M']]  = [];
            if( !isset( $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']] )) $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']] = [
                'VD' => 0,
                'mgrs' => [],

            ];

            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['VD'] += $line['S'];
            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['mgrs'][$line['U']] = 1;
//     			if( $firsts == $this->isFirstSale($line['criteria'], $line['company'], $line['M']))
//     						$R[ $line[ 'M' ]] += $line[ 'S' ];
        }


        /** 4. Прогоны по аналитике
         */
        $losses = []; // Потери

        /**
         * дебажим
         */
        $lossesDbg = [];

        $companies = CHtml::listData(Company::model()->findAll(), 'ID', 'name');
        foreach ( $R as $C => $companyData) {

            foreach ($companyData as $M => $saleData) {

                $monthVal = [];
                $monthValbyMPP = [];
                foreach ($mpp_list as $mpp) {
                    $monthVal[$mpp] = 0;
                    $monthValbyMPP[$mpp] = 0;
                }

                $firstMonthVD = 0;
                // newblock
                foreach ($saleData as $criteria => $dataItem) {
                    // замешан МПП
                    $MPPused = false;
                    foreach ($dataItem['mgrs'] as $mgr => $v) {
                        if (in_array($mgr, $mpp_list)) {
                            $MPPused = true;
                            break;
                        }
                    }

                    // варианты
                    // 1. Новая? Вот прям первая
                    if (($this->isFirstSale($criteria, $C, $M)) && $MPPused)
                    {
                        //Считаем сумму ВД новых продаж за текущий месяц по компании, за месяц+, за месяц++
                        $firstMonthVD += $dataItem['VD'];

                    }

                }
                // end of newblock


                if ($firstMonthVD > 0) {
                    //Получаем ВД за следующий месяц после первого
                    $nextMonthVD = 0;
                    if (isset($companyData[$M + 1]))
                        foreach ($companyData[$M + 1] as $criteria => $data)
                            $nextMonthVD += $data['VD'];

                    //Получаем ВД за месяц+2 после первого
                    $nextMonth2VD = 0;
                    if (isset($companyData[$M + 2]))
                        foreach ($companyData[$M + 2] as $criteria => $data)
                            $nextMonth2VD += $data['VD'];


                    if ($firstMonthVD > $nextMonthVD) {
                        if ($M + 1 <= $this->mEnd) {
                            if (!isset($losses[$M + 1])) $losses[$M + 1] = 0;

                            $losses[$M + 1] += $nextMonthVD - $firstMonthVD;

                            if ($nextMonthVD - $firstMonthVD) {
                                if (!isset($lossesDbg[$M + 1][$companies[$C]]['sum']))
                                    $lossesDbg[$M + 1][$companies[$C]]['sum'] = 0;
                                $lossesDbg[$M + 1][$companies[$C]]['sum'] += $nextMonthVD - $firstMonthVD;
                            }
                        }
                    } elseif ($firstMonthVD > $nextMonth2VD) {
                        if ($M+2 <= $this->mEnd) {
                            if (!isset($losses[$M + 2])) $losses[$M + 2] = 0;
                            $losses[$M + 2] += $nextMonth2VD - $firstMonthVD;

                            if ($nextMonth2VD - $firstMonthVD) {
                                if (!isset($lossesDbg[$M + 2][$companies[$C]]['sum']))
                                    $lossesDbg[$M + 2][$companies[$C]]['sum'] = 0;
                                $lossesDbg[$M + 2][$companies[$C]]['sum'] += $nextMonth2VD - $firstMonthVD;
                            }
                        }
                    }
                }

            }
        }

        return [
            0 => $short,
            1 => $long,
            2 => $shortDBG,
            3 => $longDBG,
            4 => $losses,
            5 => $lossesDbg
        ];
    }

    private function getDBGAr($line) {
	    if ($line['S']) {
            return ['id' => $line['SID'], 'sale' => $line['SNM'], 'sum' => round($line['S'], 2)];
        } else {
	        return null;
        }
    }

    public function getRopSales($strict = true)
    {
        $R = [];

        $DS = $this->getBaseStructureQuery();
        $DS = $this->filterManagers($DS);

        if ($strict)
            $this->strict($DS);
        else
            $DS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');

        $DS->select($this->getField('VD') . ' AS S, 
    			S.FK_control_month AS M'
        );

        $rows = $DS->queryAll();

        foreach ($rows as $row) {
            if (!isset($R[$row['M']])) $R[$row['M']] = 0;
            $R[$row['M']] += $row['S'];
        }

        return $R;
    }

    public function getMonthlyStructure_OPShort($value, $strict = true)
    {
        $DS = $this->getBaseStructureQuery();
//        $DS = $this->filterManagers($DS);
        $DS = $this->filterManagersMPP($DS);

        if ($strict) {
            $this->strict($DS);
        } else {
            $DS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');
        }

        $DS->andWhere('(E.is_long = 0 or P.is_unique = 1)');
        $DS->select(
            $this->getField($value) . ' AS S,
            S.FK_control_month AS M, 
            S.FK_manager as Mgr, 
            S.FK_company as cmp, 
            S.ID as sid,
            E.is_long as is_long,
            P.is_unique as is_unique')
            ->order('M, Mgr, cmp');

        $rows = $DS->queryAll();

        $companies = CHtml::listData(Company::model()->findAll(), 'ID', 'name');
        $users = CHtml::listData(User::model()->findAll('FK_access_group > 0'), 'ID', 'fio');

        $trues = [];
        $truesDBG = [];
        $marked = [];
        $markedDBG = [];
        foreach ($rows as $line) {

            if ($line['is_unique']) {
                if ($line['S']) {
                    if (!isset($marked[$line['M']])) $marked[$line['M']] = 0;
                    $marked[$line['M']] += $line['S'];

                    $M = Month::getMonthName($line['M']);
                    if (!isset($markedDBG[$M])) {
                        $markedDBG[$M] = [];
                    }
                    $markedDBG[$M][] = [
                        'summ' => round($line['S'], 2),
                        'manager' => $users[$line['Mgr']],
                        'company' => $companies[$line['cmp']],
                        'sid' => $line['sid'],
                    ];
                }
            }
            else {
                if ($line['S']) {
                    if (!isset($trues[$line['M']])) $trues[$line['M']] = 0;
                    $trues[$line['M']] += $line['S'];

                    $M = Month::getMonthName($line['M']);
                    if (!isset($truesDBG[$M])) {
                        $truesDBG[$M] = [];
                    }
                    $truesDBG[$M][] = [
                        'summ' => round($line['S'], 2),
                        'manager' => $users[$line['Mgr']],
                        'company' => $companies[$line['cmp']],
                        'sid' => $line['sid'],
                    ];
                }
            }

        }
        /*$this->filterTrueShorts($DS1)->select('sum(' . $this->getField($value) . ') AS S,
    			S.FK_control_month AS M')
            ->group('M');

        $this->filterTrueShorts($DEBUG)->select($this->getField($value) . ' AS S,
    			S.FK_control_month AS M, S.FK_manager as Mgr, S.FK_company as cmp, S.ID as sid')
            ->order('M, Mgr, cmp');*/



        /*$rows = $DS1->queryAll();
        foreach ($rows as $line) {
            $trues[$line['M']] = $line['S'];
        }

        $truesDBG = [];
        $rows = $DEBUG->queryAll();
        foreach ($rows as $line) {
            if ($line['S']) {
                $M = Month::getMonthName($line['M']);
                if (!isset($truesDBG[$M])) {
                    $truesDBG[$M] = [];
                }
                $truesDBG[$M][] = [
                    'summ' => round($line['S'], 2),
                    'manager' => $users[$line['Mgr']],
                    'company' => $companies[$line['cmp']],
                    'sid' => $line['sid'],
                ];
            }
        }*/


        /*$this->filterMarkedShorts($DS)->select('sum(' . $this->getField($value) . ') AS S,
    			S.FK_control_month AS M')
            ->group('M');
        $this->filterMarkedShorts($DEBUG2)->select($this->getField($value) . ' AS S,
    			S.FK_control_month AS M, S.FK_manager as Mgr, S.FK_company as cmp, S.ID as sid')
            ->order('M, Mgr, cmp');

        $marked = [];
        $rows = $DS->queryAll();
        foreach ($rows as $line) {
            $marked[$line['M']] = $line['S'];
        }

        $markedDBG = [];
        $rows = $DEBUG2->queryAll();
        foreach ($rows as $line) {
            if ($line['S']) {
                $M = Month::getMonthName($line['M']);
                if (!isset($markedDBG[$M])) {
                    $markedDBG[$M] = [];
                }
                $markedDBG[$M][] = [
                    'summ' => round($line['S'], 2),
                    'manager' => $users[$line['Mgr']],
                    'company' => $companies[$line['cmp']],
                    'sid' => $line['sid'],
                ];
            }
        }*/

        return [
            0 => $trues,
            1 => $marked,
            2 => $truesDBG,
            3 => $markedDBG,
        ];

    }


    
    
    public function getMonthlyStructure_OPLong( $value, $strict = true)
    {
        $field = $this->getField($value);
        $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList( CrmAccessDispatcher::GROUP_SALEMGR );

        //убираем Иру и Валю!
        foreach ($mpp_list as $k => $mgr) {
            if (in_array($mgr, [11])) {
                unset($mpp_list[$k]);
            }
            //Валя только для внутреннего отображения
            if (Yii::app()->user->id == 386 && in_array($mgr, [82])) {
                unset($mpp_list[$k]);
            }
//            //Костыль для удаления Чудаевой Ани до апреля 2018
//            if ($this->monthEnd < 125 && in_array($mgr, [232])) {
//                unset($mpp_list[$k]);
//            }
        }

    	$monthlyTops = [];
    	/** 1. Максимумы помесячно!
    	*/
    	$monthlyDS = $this->getBaseStructureQuery( $this->mStart-12, $this->mEnd);

        $monthlyDS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
    	// "длинные" - до периода
//        if ($strict) {
//            $this->strict($monthlyDS);
//        } else {
//            $monthlyDS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');
//        }
    	$monthlyDS = $this->filterMarkedShorts( $monthlyDS, false );
    	$monthlyDS->select( 'S.FK_control_month AS M,
    		S.FK_company AS C, sum(' .$field . ') V')
    		->group( 'M,C');
        //$monthlyDS = $this->filterManagersMPP($monthlyDS);

        $rows = $monthlyDS->queryAll();
    	foreach ( $rows as $line )
    	{
    		if( !isset( $monthlyTops[ $line['C']] )) $monthlyTops[ $line['C']] = [];
    		$monthlyTops[ $line['C']][ $line['M']] = $line['V'];
    	}

        //////////////////////////////////////////////////////////////////////////////
        $context = $this->addContextTops($monthlyTops, $field);
        $firstContext = $context[0];
        $secondContext = $context[1];
        /////////////////////////////////////////////////////////////////////

    	$honeymoons = [];
    	/** 2. По максимумам - точки начала льготных периодов
    	*/
    	foreach ( $monthlyTops as $C => $mData)
    	{
    		$toSave = false;
    		$lastM = 0;
    		$buff = [];
    		foreach ($mData as $M => $V)
    		{
    			if( !$toSave && ($M >= ($this->mStart - 2))) $toSave = true;
    			if( ($M-$lastM) >= 12) $buff[$M] = 1;
    			$lastM = $M;
    		}

    		if( $toSave )
    			$honeymoons[ $C ] = $buff;
    	}
    	foreach ($honeymoons as $C=>$mData)
    	{
    		$buff = [];
    		foreach ( $mData as $M=>$V)
    		{
    			for( $i = 0; $i<6; $i++)
    				$buff[ $M+$i ] = 1;
    		}
    		$honeymoons[ $C ] = $buff;
    	}


    	/** 3. Все продажи. сохранить
    	 */


    	$DS = $this->getBaseStructureQuery( $this->mStart-12, $this->mEnd);
        $DS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
    	$this->filterMarkedShorts($DS, false);
    	$DS->selectDistinct( "
    			
    			sum($field) as S,

    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
    			S.FK_company as C,
    			S.FK_control_month as M,
				S.FK_manager as U
    			")
    			//->andWhere($condition)
    			->group('M, C, U');

    	if ($strict) {
            $this->strict($DS);
        } else {
            $DS->andWhere('S.FK_control_month >= (' . ($this->mEnd - 1) . ')');
        }
    	$rows = $DS->queryAll();

    	$R = [];
    	foreach ($rows as $line)
    	{
    		if( !isset( $R[ $line[ 'C' ]])) $R[ $line[ 'C' ]]  = [];
    		if( !isset( $R[ $line[ 'C' ]][ $line['M']] )) $R[ $line[ 'C' ]][ $line['M']]  = [];
    		if( !isset( $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']] )) $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']] = [
    			'VD' => 0,
    			'mgrs' => [],

    		];

    		$R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['VD'] += $line['S'];
    		$R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['mgrs'][$line['U']] = 1;
//     			if( $firsts == $this->isFirstSale($line['criteria'], $line['company'], $line['M']))
//     						$R[ $line[ 'M' ]] += $line[ 'S' ];
    	}


    	/** 4. Прогоны по аналитике
    	 */
    	$newbes = []; // Новые продажи
    	$extens = []; // Расширения
    	$losses = []; // Потери

        /**
         * дебажим
         */
        $debugArray = [
            'new' => [],
            'ext' => [],
            'los' => [],
        ];

        $companies = CHtml::listData(Company::model()->findAll(), 'ID', 'name');
        $users = CHtml::listData(User::model()->findAll('FK_access_group > 0'), 'ID', 'fio');

    	foreach ( $R as $C => $companyData)
    	{
            $company = Company::model()->findByPk($C);
            $globMPP = $company->FK_manager;

    		foreach ( $companyData as $M => $saleData)
    		{

                $monthVal = [];
                $monthValbyMPP = [];
                foreach($mpp_list as $mpp) {
                    $monthVal[$mpp] = 0;
                    $monthValbyMPP[$mpp] = 0;
                }
                $mpp = 0;

                $firstMonthVD = 0;
    			// newblock
    			foreach ( $saleData as $criteria => $dataItem )
    			{
    				// замешан МПП
    				$MPPused = false;	
    				foreach ($dataItem['mgrs'] as $mgr=>$v)
    				{
    					if( in_array($mgr, $mpp_list)) {
    					    $MPPused = true;
    					    $mpp = $mgr;
    					    break;
                        }
    				}
    				
    				// варианты
    				// 1. Новая? Вот прям первая
    				if( 
    					($this->isFirstSale($criteria, $C, $M)) &&
    					$MPPused
    				)
    						
    				//	isset( $honeymoons[ $C ][ $M ]) &&
    				//	!isset( $honeymoons[ $C ][ $M-1 ])
    				
    				{					
    					if(
    						isset( $honeymoons[ $C ][ $M ]) &&
    						!isset( $honeymoons[ $C ][ $M-1 ])
    					)
    					{	
    						if(!isset($newbes[ $M ])) $newbes[ $M ] = 0;
    						$newbes[ $M ] += $dataItem['VD'];

    						if ($dataItem['VD'])
    						    if (!empty($debugArray['new'][$M][$companies[$C]]['sum'])) {
                                    $debugArray['new'][$M][$companies[$C]]['sum'] += round($dataItem['VD'], 2);
                                } else {
                                    $debugArray['new'][$M][$companies[$C]] = [
                                        'sum' => round($dataItem['VD'], 2),
                                        'mng' => $mpp,
                                    ];
                                }

                            //Считаем сумму ВД новых продаж за текущий месяц по компании
                            $firstMonthVD += $dataItem['VD'];

                            //иначе добавляем + к месячному приросту
    					} else/*if (isset( $honeymoons[ $C ][ $M ]))*/ {
                            $monthVal[$mpp] += $dataItem['VD'];
                            $monthValbyMPP[$mpp] += $dataItem['VD'];
    					}



    				}
    				else 
    				{
    					// точно - без новых!
                        if ($MPPused) {
                            $monthValbyMPP[$mpp] += $dataItem['VD'];
                            $monthVal[$mpp] += $dataItem['VD'];
                        } else {
                            if (!isset($monthVal[$globMPP])) $monthVal[$globMPP] = 0;

                            $monthVal[$globMPP] += $dataItem['VD'];
                        }
    				}

    			}
    			// end of newblock


                //Если есть ВД первого месяца, считаем потери за месяц+ и месяц++ относительно первого
                if ($firstMonthVD > 0) {
                    //Получаем ВД за следующий месяц после первого
                    $nextMonthVD = 0;
                    if (isset($companyData[$M + 1])) {
                        foreach ($companyData[$M + 1] as $criteria => $data) {
                            $nextMonthVD += $data['VD'];
                        }
                    }

                    //Получаем ВД за месяц+2 после первого
                    $nextMonth2VD = 0;
                    if (isset($companyData[$M + 2])) {
                        foreach ($companyData[$M + 2] as $criteria => $data) {
                            $nextMonth2VD += $data['VD'];
                        }
                    }


                    if ($firstMonthVD > $nextMonthVD) {
                        if( !isset($losses[$M+1])) $losses[$M+1] = 0;
                        $losses[$M + 1] += $nextMonthVD - $firstMonthVD;

                        if (!isset($debugArray['los'][$M + 1][$companies[$C]]['sum']))
                            $debugArray['los'][$M + 1][$companies[$C]]['sum'] = 0;
                        $debugArray['los'][$M + 1][$companies[$C]]['sum'] += $nextMonthVD - $firstMonthVD;
                        $debugArray['los'][$M + 1][$companies[$C]]['companyId'] = $C;
                    }
                    //Только при условии, что не попали в потерю первого месяца
                    elseif ($firstMonthVD > $nextMonth2VD) {
                        if( !isset($losses[$M+2])) $losses[$M+2] = 0;
                        $losses[$M+2] += $nextMonth2VD - $firstMonthVD;

                        if (!isset($debugArray['los'][$M + 2][$companies[$C]]['sum']))
                            $debugArray['los'][$M + 2][$companies[$C]]['sum'] = 0;
                        $debugArray['los'][$M + 2][$companies[$C]]['sum'] += $nextMonth2VD - $firstMonthVD;
                        $debugArray['los'][$M + 2][$companies[$C]]['companyId'] = $C;
                    }
                }


    			
    			// $monthVal  - объем за месяц
    			// $monthValbyMPP - объем за месяц с помощью МПП
    			// проверим, есть ли объемный прирост
    			$best = 0;
    			
    			for ( $shift=1; $shift < 12; $shift ++)
    			{
    				if( isset($monthlyTops[ $C][ $M - $shift] )
    					&& ($monthlyTops[ $C][ $M - $shift] > $best) 
    				)
    				$best = $monthlyTops[ $C][ $M - $shift ]; 
    					
    			}

    			foreach ($monthVal as $mpp => $mVal) {
                    if (!in_array($mpp, $mpp_list)) {
                        if (!in_array($globMPP, $mpp_list)) continue;
                        $mpp = $globMPP;
                    }

                    //Для корректного расчета суммы допродажи после первой контекстной продажи
                    $addContext = 0;
                    if (isset($firstContext[$C][$M-1]) && !isset($secondContext[$C][$M]))
                        $addContext = $firstContext[$C][$M-1];
                    elseif (isset($firstContext[$C][$M-1]) && isset($secondContext[$C][$M]) && $secondContext[$C][$M] < $firstContext[$C][$M])
                        $addContext += $firstContext[$C][$M-1] - $secondContext[$C][$M];

                    $mVal += $addContext;

                    if( $mVal > $best ) //есть рост!!
                    {
                        if(!isset( $extens[ $M ]))
                            $extens[ $M ] = 0;


                        // 1. Это льготный период?
                        if( isset( $honeymoons[ $C ][ $M])) {
                            $extens[$M] += $mVal - $best;
                            if ($mVal - $best)
                                $debugArray['ext'][$M][$companies[$C]] = [
                                    'sum' => round($mVal - $best, 2),
    //                                'mng' => $mpp,
                                ];
                        } else {
                            $extens[ $M ] += min($mVal - $best, $monthValbyMPP[$mpp]);

                            if(min($mVal - $best, $monthValbyMPP[$mpp]))
                                $debugArray['ext'][$M][$companies[$C]] = [
                                    'sum' => round(min($mVal - $best, $monthValbyMPP[$mpp]), 2),
    //                                'mng' => $mpp,
                                ];
                        }
                    }
    		    }
    		}
    	}


        //Вычтем отмеченные НЕПОТЕРИ
        $noLosses = SalesLosses::model()->findAll('type = "total"');
        foreach ($noLosses as $nl) {
            if (isset($losses[$nl->month])) {
                $losses[$nl->month] -= $nl->sum;
            }
        }

    	
    	
    	// Очистим данные вне периода и на выход
    	$R = [
    		[],	//new
    		[],	//loss
    		[],	//ext
    		[],	//sum
            [], //dbg
    	];

    	unset($debugArray['los'][$this->mEnd + 1]);
    	
    	for ( $i = $this->mStart; $i<=$this->mEnd; $i++)
    	{
    		if( isset( $newbes[ $i ])) $R[0][$i] = $newbes[$i]; else $R[0][$i] = 0;
    		if( isset( $losses[ $i ])) $R[1][$i] = $losses[$i]; else $R[1][$i] = 0;
    		if( isset( $extens[ $i ])) $R[2][$i] = $extens[$i]; else $R[2][$i] = 0;
    		$R[3][$i] = $R[0][$i] + $R[1][$i] + $R[2][$i];
    	}
        $R[4] = $debugArray;

        return $R;

    }


    public function getMonthlyStructure_OPLongByMpp( $value, $strict = true) {
        $field = $this->getField($value);
        $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList( CrmAccessDispatcher::GROUP_SALEMGR );

        //добавляем Чумак!
        if (!in_array(11, $mpp_list)) {
            $mpp_list[] = 11;
        }

        $monthlyTops = [];
        /** 1. Максимумы помесячно!
         */
        $monthlyDS = $this->getBaseStructureQuery( $this->mStart-12, $this->mEnd);

        $monthlyDS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
        // "длинные" - до периода
//        if ($strict) {
//            $this->strict($monthlyDS);
//        } else {
//            $monthlyDS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');
//        }
        $monthlyDS = $this->filterMarkedShorts( $monthlyDS, false );
        $monthlyDS->select( 'S.FK_control_month AS M,
		S.FK_company AS C, sum(' .$field . ') V')
            ->group( 'M,C');
        //$monthlyDS = $this->filterManagersMPP($monthlyDS);

        $rows = $monthlyDS->queryAll();
        foreach ( $rows as $line )
        {
            if( !isset( $monthlyTops[ $line['C']] )) $monthlyTops[ $line['C']] = [];
            $monthlyTops[ $line['C']][ $line['M']] = $line['V'];
        }

        //////////////////////////////////////////////////////////////////////////////
        $context = $this->addContextTops($monthlyTops, $field);
        $firstContext = $context[0];
        $secondContext = $context[1];
        /////////////////////////////////////////////////////////////////////

        $honeymoons = [];
        /** 2. По максимумам - точки начала льготных периодов
         */
        foreach ( $monthlyTops as $C => $mData)
        {
            $toSave = false;
            $lastM = 0;
            $buff = [];
            foreach ($mData as $M => $V)
            {
                if( !$toSave && ($M >= ($this->mStart - 2))) $toSave = true;
                if( ($M-$lastM) >= 12) $buff[$M] = 1;
                $lastM = $M;
            }

            if( $toSave )
                $honeymoons[ $C ] = $buff;
        }
        foreach ($honeymoons as $C=>$mData)
        {
            $buff = [];
            foreach ( $mData as $M=>$V)
            {
                for( $i = 0; $i<6; $i++)
                    $buff[ $M+$i ] = 1;
            }
            $honeymoons[ $C ] = $buff;
        }


        /** 3. Все продажи. сохранить
         */


        $DS = $this->getBaseStructureQuery( $this->mStart - 2, $this->mEnd);
        $DS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
        $this->filterMarkedShorts($DS, false);
        $DS->selectDistinct( "
			
			sum($field) as S,

			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
			S.FK_company as C,
			S.FK_control_month as M,
			S.FK_manager as U
			")
            ->group('M, criteria, C, U');

        if ($strict) {
            $this->strict($DS);
        } else {
            $DS->andWhere('S.FK_control_month >= (' . ($this->mEnd - 1) . ')');
        }
        $rows = $DS->queryAll();

        $R = [];
        foreach ($rows as $line)
        {
            if( !isset( $R[ $line[ 'C' ]])) $R[ $line[ 'C' ]]  = [];
            if( !isset( $R[ $line[ 'C' ]][ $line['M']] )) $R[ $line[ 'C' ]][ $line['M']]  = [];
            if( !isset( $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']] )) $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']] = [
                'VD' => 0,
                'mgrs' => [],

            ];

            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['VD'] += $line['S'];
            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['mgrs'][$line['U']] = 1;
//     			if( $firsts == $this->isFirstSale($line['criteria'], $line['company'], $line['M']))
//     						$R[ $line[ 'M' ]] += $line[ 'S' ];
        }


        /** 4. Прогоны по аналитике
         */
        $newbes = []; // Новые продажи
        $extens = []; // Расширения
        $losses = []; // Потери

        /**
         * дебажим
         */
        $debugArray = [
            'new' => [],
            'ext' => [],
            'los' => [],
        ];

        $companies = CHtml::listData(Company::model()->findAll(), 'ID', 'name');
        $users = CHtml::listData(User::model()->findAll('FK_access_group > 0'), 'ID', 'fio');

        foreach ( $R as $C => $companyData)
        {
            $company = Company::model()->findByPk($C);
            $globMPP = $company->FK_manager;

            foreach ( $companyData as $M => $saleData)
            {

                $monthVal = [];
                $monthValbyMPP = [];
                foreach($mpp_list as $mpp) {
                    $monthVal[$mpp] = 0;
                    $monthValbyMPP[$mpp] = 0;
                }
                $mpp = 0;

                $firstMonthVD = 0;
                // newblock
                foreach ( $saleData as $criteria => $dataItem )
                {
                    // замешан МПП
                    $MPPused = false;
                    foreach ($dataItem['mgrs'] as $mgr=>$v)
                    {
                        if( in_array($mgr, $mpp_list)) {
                            $MPPused = true;
                            $mpp = $mgr;
                            break;
                        }
                    }

                    // варианты
                    // 1. Новая? Вот прям первая
                    if(
                        ($this->isFirstSale($criteria, $C, $M)) &&
                        $MPPused
                    )

                        //	isset( $honeymoons[ $C ][ $M ]) &&
                        //	!isset( $honeymoons[ $C ][ $M-1 ])

                    {
                        if(
                            isset( $honeymoons[ $C ][ $M ]) &&
                            !isset( $honeymoons[ $C ][ $M-1 ])
                        )
                        {
                            if(!isset($newbes[ $M ][$mpp])) $newbes[ $M ][$mpp] = 0;
                            $newbes[ $M ][$mpp] += $dataItem['VD'];

                            if ($dataItem['VD'])
                                if (!empty($debugArray['new'][$M][$companies[$C]]['sum'])) {
                                    $debugArray['new'][$M][$companies[$C]]['sum'] += round($dataItem['VD'], 2);
                                } else {
                                    $debugArray['new'][$M][$companies[$C]] = [
                                        'sum' => round($dataItem['VD'], 2),
                                        'mng' => $mpp,
                                    ];
                                }
                            //иначе добавляем + к месячному приросту
                        } else/*if (isset( $honeymoons[ $C ][ $M ]))*/ {
                            $monthVal[$mpp] += $dataItem['VD'];
                            $monthValbyMPP[$mpp] += $dataItem['VD'];
                        }


                        //Считаем сумму ВД новых продаж за текущий месяц по компании, за месяц+, за месяц++
                        $firstMonthVD += $dataItem['VD'];

                    }
                    else
                    {
                        // точно - без новых!
                        if ($MPPused) {
                            $monthValbyMPP[$mpp] += $dataItem['VD'];
                            $monthVal[$mpp] += $dataItem['VD'];
                        } else {
                            if (!isset($monthVal[$globMPP])) $monthVal[$globMPP] = 0;

                            $monthVal[$globMPP] += $dataItem['VD'];
                        }
                    }

                }
                // end of newblock


                if ($firstMonthVD > 0) {
                    //Получаем ВД за следующий месяц после первого
                    $nextMonthVD = 0;
                    if (isset($companyData[$M + 1]))
                        foreach ($companyData[$M + 1] as $criteria => $data)
                            $nextMonthVD += $data['VD'];

                    //Получаем ВД за месяц+2 после первого
                    $nextMonth2VD = 0;
                    if (isset($companyData[$M + 2]))
                        foreach ($companyData[$M + 2] as $criteria => $data)
                            $nextMonth2VD += $data['VD'];



                    if ($firstMonthVD > $nextMonthVD) {
                        if( !isset($losses[$M+1][$mpp])) $losses[$M+1][$mpp] = 0;
                    $losses[$M + 1][$mpp] += $nextMonthVD - $firstMonthVD;

                    if ($nextMonthVD - $firstMonthVD) {
                        if (!isset($debugArray['los'][$M + 1][$companies[$C]]['sum']))
                            $debugArray['los'][$M + 1][$companies[$C]]['sum'] = 0;
                        $debugArray['los'][$M + 1][$companies[$C]]['sum'] += $nextMonthVD - $firstMonthVD;
                    }
                }
                    elseif ($firstMonthVD > $nextMonth2VD) {
                        if( !isset($losses[$M+2][$mpp])) $losses[$M+2][$mpp] = 0;
                        $losses[$M+2][$mpp] += $nextMonth2VD - $firstMonthVD;

                        if ($nextMonth2VD - $firstMonthVD) {
                            if (!isset($debugArray['los'][$M + 2][$companies[$C]]['sum']))
                                $debugArray['los'][$M + 2][$companies[$C]]['sum'] = 0;
                            $debugArray['los'][$M + 2][$companies[$C]]['sum'] += $nextMonth2VD - $firstMonthVD;
                        }
                    }
                }



                // $monthVal  - объем за месяц
                // $monthValbyMPP - объем за месяц с помощью МПП
                // проверим, есть ли объемный прирост
                $best = 0;

                for ( $shift=1; $shift < 12; $shift ++)
                {
                    if( isset($monthlyTops[ $C][ $M - $shift] )
                        && ($monthlyTops[ $C][ $M - $shift] > $best)
                    )
                        $best = $monthlyTops[ $C][ $M - $shift ];

                }

                foreach ($monthVal as $mpp => $mVal) {
                    if (!in_array($mpp, $mpp_list)) {
                        if (!in_array($globMPP, $mpp_list)) continue;
                        $mpp = $globMPP;
                    }

                    //Для корректного расчета суммы допродажи после первой контекстной продажи
                    $addContext = 0;
                    if (isset($firstContext[$C][$M-1]) && !isset($secondContext[$C][$M]))
                        $addContext = $firstContext[$C][$M-1];
                    elseif (isset($firstContext[$C][$M-1]) && isset($secondContext[$C][$M]) && $secondContext[$C][$M] < $firstContext[$C][$M])
                        $addContext += $firstContext[$C][$M-1] - $secondContext[$C][$M];

                    $mVal += $addContext;

                    if( $mVal > $best ) //есть рост!!
                    {
                        if(!isset( $extens[ $M ][$mpp]))
                            $extens[ $M ][$mpp] = 0;


                        // 1. Это льготный период?
                        if( isset( $honeymoons[ $C ][ $M])) {
                            $extens[$M][$mpp] += $mVal - $best;
                            if ($mVal - $best)
                                $debugArray['ext'][$M][$companies[$C]] = [
                                    'sum' => round($mVal - $best, 2),
//                                'mng' => $mpp,
                                ];
                        } else {
                            $extens[ $M ][$mpp] += min($mVal - $best, $monthValbyMPP[$mpp]);

                            if(min($mVal - $best, $monthValbyMPP[$mpp]))
                                $debugArray['ext'][$M][$companies[$C]] = [
                                    'sum' => round(min($mVal - $best, $monthValbyMPP[$mpp]), 2),
//                                'mng' => $mpp,
                                ];
                        }
                    }
                }
            }
        }


        // Очистим данные вне периода и на выход
        $R = [
            [],	//new
            [],	//loss
            [],	//ext
            [],	//sum
            [], //dbg
        ];

        unset($debugArray['los'][$this->mEnd + 1]);

        for ( $i = $this->mStart; $i<=$this->mEnd; $i++)
        {
            if( isset( $newbes[ $i ])) $R[0][$i] = $newbes[$i]; else $R[0][$i] = 0;
            if( isset( $losses[ $i ])) $R[1][$i] = $losses[$i]; else $R[1][$i] = 0;
            if( isset( $extens[ $i ])) $R[2][$i] = $extens[$i]; else $R[2][$i] = 0;
            //$R[3][$i] = $R[0][$i] + $R[1][$i] + $R[2][$i];
        }
        //$R[4] = $debugArray;

        return $R;

    }
    
    
    public function _old_getMonthlyStructure_OPLong( $value, $strict = true)
    {
    	$preDS = $this->getBaseStructureQuery( $this->mStart-16, $this->mStart-1);
    	// "длинные" - до париода
    	$preDS = $this->filterMarkedShorts( $preDS, false );
    	$preDS->select( 'max(S.FK_control_month) AS M,
    		S.FK_company AS C')
    		->group( 'C');
    		$rows = $preDS->queryAll();
    		$_befores = []; // последний раз до периода
    		foreach ($rows as $line)
    		{
    			$_befores[ $line[ 'C' ]] = $line['M'];
    		}
    		
    		$intoDS = $this->getBaseStructureQuery( );
    		// "длинные" - первый в париоде
    		$intoDS = $this->filterMarkedShorts( $intoDS, false );
    		$intoDS->select( 'min(S.FK_control_month) AS M,
    		S.FK_company AS C')
    		->group( 'C');
    		$rows = $intoDS->queryAll();
    		$_intos = []; // последний раз до периода
    		foreach ($rows as $line)
    		{
    			$_intos[ $line[ 'C' ]] = $line['M'];
    		}
    		
    		
    		// Непосредственно:
    		
    		$DS = $this->getBaseStructureQuery();
    		$DS = $this->filterManagers( $DS);
    		$DS = $this->filterManagersMPP( $DS);
    		
    		if( $strict )
    			$this->strict( $DS );
    			else
    				$DS->andWhere( 'S.FK_control_month >=  (' . ($this->mEnd - 1) . ')' );
    				
    				// оба условия разовости в минус
    				$DS = $this->filterMarkedShorts( $DS, false );
    				
    				$DS->select( 'sum(' . $this->getField( $value ) . ') AS S,
    		S.FK_control_month AS M, S.FK_company AS C')
    		->group( 'M, C');
    		
    		$newbes = [];
    		$others = [];
    		
    		$rows = $DS->queryAll();
    		foreach ($rows as $line)
    		{
    			$C = $line['C'];
    			
    			if(
    					($_intos[$C] == $line['M']) &&
    					(
    							!isset( $_befores[$C]) ||
    							( $line['M']- $_befores[$C] ) > 15
    							)
    					)
    			{
    				if(!isset( $newbes[ $line['M']])) $newbes[ $line['M']] = 0;
    				$newbes[ $line['M']] += $line['S'];
    			}
    			else
    			{
    				if(!isset( $others[ $line['M']])) $others[ $line['M']] = 0;
    				$others[ $line['M']] += $line['S'];
    			}
    			
    		}
    		
    		
    		
    		return [
    				0 => $newbes,
    				1 => $others
    		];
    		
    }
    
    
    public function getMonthlyClientsNumber_OP( $value, $strict = true)
    {
        if (Yii::app()->user->id == 386) {
            $mgrList = array_merge(
                Yii::app()->accDispatcher->getTriggerGroupList(AbstractAccessDispatcher::GROUP_SALEMGR),
                Yii::app()->accDispatcher->getTriggerGroupList(AbstractAccessDispatcher::GROUP_ACC_MGR)
            );
            natsort($mgrList);
            $this->managers = $mgrList;
            $this->managers_backup = $mgrList;
        }
    	$maxCube = [];
    	$mCompanies = [];
    	// В периоде: только длинные!    	
    	$DS = $this->getBaseStructureQuery();
    	$DS = $this->filterMarkedShorts( $DS, false );
    	$this->strict( $DS );
//        if (Yii::app()->user->id == 386) {
//            $DS = $this->filterManagersMPP($DS);
//        } else {
            $DS = $this->filterManagers($DS);
//        }
    	$DS->select( 'sum('.$this->getField($value).') AS S,
    		S.FK_company AS C, S.FK_control_month as M, C.name as NM')
    		->group( 'M, C');
    		
    	$rows = $DS->queryAll();
    	foreach ($rows as $line)
    	{
    		if( !isset( $maxCube[ $line[ 'C']])) {
                $maxCube[$line['C']] = [];
                $mCompanies[$line['C']] = [];
            }
    		$maxCube[$line['C']][ $line['M']] = $line['S'];
            $mCompanies[$line['C']][ $line['M']] = $line['NM'];
    	}
    	
    	
    	// До периода: только длинные,  c фильтрацией по наличию в периоде!
    	$DS = $this->getBaseStructureQuery($this->mStart-13, $this->mStart-1);
    	$DS = $this->filterMarkedShorts( $DS, false );
    	$this->strict( $DS );
//        if (Yii::app()->user->id == 386) {
//            $DS = $this->filterManagersMPP($DS);
//        } else {
            $DS = $this->filterManagers($DS);
//        }
    	$DS->select( 'sum('.$this->getField('vd').') AS S,
    		S.FK_company AS C, S.FK_control_month as M, C.name as NM')
    		->group( 'M, C');
    	
    	$rows = $DS->queryAll();
    	foreach ($rows as $line)
    	{
    		// вот это фильтрация ))
    		if(!isset($maxCube[$line['C']])) continue;
    		$maxCube[$line['C']][ $line['M']] = $line['S'];
            $mCompanies[$line['C']][ $line['M']] = $line['NM'];
    	}
    		
    	
    	// постобработка ))
    	$newbes = [];
    	$oldies = [];
    	$newbesCmp = [];
    	$oldiesCmp = [];
    	
    	foreach ($maxCube as $c => $data)
    	{
    		ksort( $data);
    		
    		$lastMax = 0;
    		$lastMonth = 0;
    		
    		foreach ($data as $month => $val)
    		{
    		
    			if( $month >= $this->mStart )
    			{
    				// первые
    				if( $lastMonth == 0 || $month-$lastMonth > 12)
    				{
    					if(!isset( $newbes[$month])) $newbes[$month] = 0;
    					$newbes[$month] ++;
                        $newbesCmp[$month][] = $mCompanies[$c][$month];
    				}
    				elseif( $val > $lastMax )
    				{
    					if(!isset( $oldies[$month])) $oldies[$month] = 0;
    					$oldies[$month] ++;
                        $oldiesCmp[$month][] = $mCompanies[$c][$month];
    				}
    				
    			}
    			
    			if( $val > $lastMax)
    				$lastMax = $val;
    			$lastMonth = $month;
    		}
    	}
    	
    	return [
    		0 => $newbes,
    		1 => $oldies,
            2 => $newbesCmp,
            3 => $oldiesCmp
    	];
    		
    }
    
    
    
    public function getMonthlyStructure_ACCLong($value, $strict = true)
    {
    	// точки "старт квартала"
    	$trimesters = [];
    	for ($i = $this->mStart; $i <= $this->mEnd; $i++)
    	{
    		$trimesters[ Month::getTrimesterStart($i) ] = [];
    	}
    	
    	// белые листы "старых клиентов" на начало квартала
    	foreach ($trimesters as $start=>$v)
    	{
    		$trimesters[ $start ] = $this->_getTrimesterClientOldies( $start );
    	}

        $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_SALEMGR);
        $acc_list = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_ACC_MGR);

        $allmgr = array_merge($mpp_list, $acc_list);

        // Непосредственно:
        //$DS = $this->getBaseStructureQuery($this->mStart - 3);
        $DS = $this->getBaseStructureQuery();
        //категории документов - CRO SEO Реклама Поддержка
        $DS->andWhere('SK.FK_category IN (51, 3, 1, 4)');
        $DS->andWhere('NOT (Prf.FK_department = 6 AND (P.FK_sale_product_kind IN (10, 16, 19, 30, 67) OR E.ID = 185))');
//        $DS->andWhere('S.FK_manager IN (:mgr)', [':mgr' => implode(', ', $allmgr)]);
        // оба условия разовости в минус
        $DS = $this->filterMarkedShorts($DS, false);
//    	$DS = $this->filterManagers($DS, 'C.FK_manager');

        //if ($strict) {
            //$this->strict($DS);
        //} else {
            //$DS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');
        //}


        $DS->select('sum(' . $this->getField($value) . ') AS S,
    		S.FK_control_month AS M, S.FK_company AS C,
    		S.FK_manager AS FMg,
    		SK.FK_category AS Ctg')
            ->group('M, C');

        $mpps = [];
        $accs = [];
        $summs = [];
        $losses = [];
    		
    	$rows = $DS->queryAll();
        foreach ($rows as $line) {
            if (in_array($line['Ctg'], [51, 3, 1, 4])) {
                if (in_array($line['FMg'], $mpp_list)) {
                    if (!isset($mpps[$line['M']])) {
                        $mpps[$line['M']] = 0;
                    }
                    $mpps[$line['M']] += $line['S'];
                }
                if (in_array($line['FMg'], $acc_list)) {
                    if (!isset($accs[$line['M']])) {
                        $accs[$line['M']] = 0;
                    }
                    $accs[$line['M']] += $line['S'];
                }

                //Общие суммы ВД за месяц по компаниям
                if (!isset($summs[$line['C']][$line['M']]))
                    $summs[$line['C']][$line['M']] = 0;
                $summs[$line['C']][$line['M']] += $line['S'];
            }
        }

        $debugLosses = [];
        foreach ($summs as $C => $data) {
            foreach ($data as $M => $summ) {

                if ($M < $this->mEnd && $M >= $this->mStart - 1) {

                    if (isset($summs[$C][$M+1])) {
                        if ($summ > $summs[$C][$M+1]) {

                            if (isset($summs[$C][$M-2]) && $summs[$C][$M-2] > 0) {
                                if (!isset($losses[1][$M+1])) $losses[1][$M+1] = 0;
                                $losses[1][$M + 1] += $summs[$C][$M + 1] - $summ;

                                if (!isset($debugLosses[1][$M+1][$C]))
                                    $debugLosses[1][$M+1][$C] = 0;
                                $debugLosses[1][$M+1][$C] += $summs[$C][$M + 1] - $summ;
                            }
                            else  {
                                if (!isset($losses[0][$M+1])) $losses[0][$M+1] = 0;
                                $losses[0][$M+1] += $summs[$C][$M+1] - $summ;

                                if (!isset($debugLosses[0][$M+1][$C]))
                                    $debugLosses[0][$M+1][$C] = 0;

                                $debugLosses[0][$M+1][$C] += $summs[$C][$M + 1] - $summ;
                            }
                        }
                    }

                }
            }
        }

        $test = 0;
    		
    	return [
    		0 => $accs,
    		1 => $mpps,
            //2 => $losses[0],
            //3 => $losses[1]
    	];
    }
    
    
    /**
     * Белый список клиентов на началоквартала
     * т.е. все у кого были "длинные" продажи в течение года до того
     * @param unknown $start
     */
    
    private function _getTrimesterClientOldies( $start )
    {
    	$DS = $this->getBaseStructureQuery( $start-13, $start-1);
    	$DS = $this->strict( $DS );
    	// оба условия разовости в минус
    	$DS = $this->filterMarkedShorts( $DS, false );
    			
    	$DS->selectDistinct( 'S.FK_company AS C');
    		
    	return $DS->queryColumn();
    }
    
    
    private function filterTrueShorts( &$DS, $positive = true)
    {
//    	$cond = 'P.FK_sale_product_kind IN (10, 17, 19) OR P.FK_sale_prod_elaboration IN (185, 216)';
    	//$cond = 'P.FK_sale_product_kind IN (10, 17, 19) OR E.is_long = 0';
        $cond = 'E.is_long = 0';
    	if( $positive)
    		$DS->andWhere( $cond );
    	else 
    		$DS->andWhere( " NOT ($cond)" );
    	return $DS;
    }
    
    private function filterMarkedShorts( &$DS, $positive = true)
    {
    	$DS=$this->filterTrueShorts($DS, false);
    	$cond = 'P.is_unique>0';
    	if( $positive)
    		$DS->andWhere( $cond );
    	else
    		$DS->andWhere( " NOT ($cond)" );
    	return $DS;
    }
    
    private function strict( &$DS)
    {
    	$DS->andWhere('(
    			( S.W_lock = "Y" ) OR
        		( S.FK_control_month <= ' . $this->mEnd . ' AND SMM.payed > 0))');
//        		( S.FK_control_month = ' . $this->mEnd . ' AND SMM.payed >= SMM.total * 0.1))');
    	return $DS;
    }

    private function lowStrict(&$DS)
    {
        $DS->andWhere('(
    			( S.W_lock = "Y" ) OR
        		( S.FK_control_month = ' . $this->mStart . ' AND SMM.payed > 0))');
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
    	
    	
    	
//     	elseif ($this->field == 2)
//     	return '(1-(P.outlay+P.discost)/100) * P.total/(1-P.discost/100)';
//     	elseif ($this->field == 3)
//     	return '(1-(P.outlay2+P.discost)/100) * P.total/(1-P.discost/100)';
//     	else
//     		return '(1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)';
    }
    
    private function getBaseStructureQuery( $start = 0, $end = 0)
    {
    	if( $start == 0) $start = $this->mStart;
    	if( $end == 0) $end = $this->mEnd;
    	
    	$DS = Yii::app()->db->createCommand();

    	$DS->from('{{services_provided}} servpov')
            ->join('{{sales_products}} P', 'P.ID = servpov.FK_sale_product')
    		->join('{{sales}} S', 'P.FK_sale = S.ID')
    		->join('{{sales_products_kinds}} SK', 'P.FK_sale_product_kind = SK.ID')
    		->join('{{companies}} C', 'S.FK_company = C.ID')
    		->join('{{managers}} Prf', 'P.FK_performer = Prf.ID')
    		->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
    		->leftJoin( '{{sales_summaries}} SMM', 'SMM.FK_sale = S.ID');

        $startMonth = Month::getMonthIndex($start, 'M');
        $startYear = Month::getMonthIndex($start, 'Y');

        $endMonth = Month::getMonthIndex($end, 'M');
        $endYear = Month::getMonthIndex($end, 'Y');

        $DS->where('
            ( ( month(P.end_date) >= '.$startMonth.' AND year(P.end_date) = '.$startYear.' ) OR ( year(P.end_date) > '.$startYear.' ) )
            AND
            ( ( month(P.end_date) <= '.$endMonth.' AND year(P.end_date) = '.$endYear.' ) OR ( year(P.end_date) < '.$endYear.' ) )
        ');
    	
    	$DS//->andWhere( 'S.FK_control_month >= '.$start)
    		//->andWhere('S.FK_control_month <= '.$end)
    		->andWhere('P.active > 0')
			->andWhere('S.active > 0')
			->andWhere('C.active > 0')
			->andWhere('S.FK_sale_state != 3');
    	
    	return $DS;
    }

    /**
     * @param CDbCommand $DS
     * @param string $field
     * @return CDbCommand
     */
    private function filterManagers( $DS, $field = 'S.FK_manager')
    {
    	if( count( $this->managers) && is_array($this->managers))
    		$DS->andWhere( $field . ' IN (' . implode(', ', $this->managers) . ')');
    	else 
    		$DS->andWhere( $field . ' IN (' . implode(', ', $this->managers_backup) . ')');
    	return $DS;
    } 
    
    private function filterManagersMPP( $DS)
    {
        /*$managers = $this->getParameterList('managers', false, 414);
        $mpp_list = [];
        foreach ($managers as $id => $name) {
            array_push($mpp_list, $id);
        }*/
    	$mpp_list = Yii::app()->accDispatcher->getTriggerGroupList( CrmAccessDispatcher::GROUP_SALEMGR );
    	$DS->andWhere( 'S.FK_manager IN (' . implode(', ', $mpp_list) . ')');
    	return $DS;
    } 
    
    public function setDefaults($isRop = false)
    {
    	$this->month = 6;
    	if ($this->interval_year)
            $this->month = 12;
    	
    	if( !is_array($this->managers) || count( $this->managers) == 0)
    		$this->managers_backup = array_keys($this->getParameterList('managers', $isRop));

    	if (!empty($this->settedMonth)) {
            $this->mEnd = $this->settedMonth;
        } else {
            $this->mEnd = Month::getQueryID(date('m'), date('Y'));
        }
    	$this->mStart = $this->mEnd - $this->month + 1;
    	
        $start = new DateTime(Month::getInnerDate($this->mEnd, date('Y-m-d')));
        $end = new DateTime(Month::getInnerDate($this->mEnd, date('Y-m-d')));
        $interval = new DateInterval('P'.($this->month-1).'M');
        $interval->invert = 1;
        $end->add($interval);
        $this->monthStart = $end->format('m');
        $this->monthEnd = $start->format('m');
        $this->yearStart = $end->format('Y');
        $this->yearEnd = $start->format('Y');

        $endDate = new DateTime(Month::getInnerDate($this->mEnd, date('Y-m-d')));
        $interval = new DateInterval('P'.($this->month-1).'M');
        $interval->invert = 1;
        $startDate = new DateTime(Month::getInnerDate($this->mEnd, date('Y-m-d')));
        $startDate->add($interval);
        $startDate->setDate($startDate->format('Y'), $startDate->format('m'), 1);
        $endDate->setTime(23, 59, 59);

        $this->endDate = $endDate;
        $this->startDate = $startDate;       
    }
    public function setMonth($month){
        $this->month = $month;
    }
    protected function prepareReport()
    {
        //1. Интервал (мин и макс) айди месяцев по заданным фильтрам
        $monthsLimits = Month::getQueryLimits($this->monthStart, $this->yearStart, $this->monthEnd, $this->yearEnd);
        $this->monthLimits = $monthsLimits;
        return $monthsLimits;

    }


    public function performReport()
    {
        $this->setDefaults();
        $this->fillFirstTimesTable(); // вспомсписки для проверки "первости"
        $this->fillSalesWhiteList();  // белый список продаж на 2 мес.
        
      
                
        $monthsLimits = $this->prepareReport();
        $structSales = $this->getStructSales();

        $activeClients = $this->getActiveClients($monthsLimits);
        $payments = $this->getPayment();



            $result = array(

                [
                    'header' => 'Общие показатели',
                    'items' => [
                        'sales_val' => 1,
                        'struct_sales_vd' => 1,
                        'struct_sales_city' => 1,
                        'sales_val_nodev' => 1,
                        'struct_sales_vd_nodev' => 1,
                        'struct_sales_city_nodev' => 1,
                        /*'payment' => ['data' => $payments],
                        'active_clients' => ['data' => $activeClients],*/
                    ]
                ],/*
                [
                    'header' => 'Результативность Лидогенерации и Маркетинга',
                    'items' => [
                        'leads__weekly_by_types' => ['class' => 'col-md-8', 'data' => 1],
                        'leads__monthly_by_types' => ['class' => 'col-md-4', 'data' => 1],
                    ]
                ],
                [
                    'header' => 'Результативность Отдела продаж',
                    'items' => [
                        'op_quality__long_sales' => ['class' => 'col-md-4', 'data' => 1],
                        'op_quality__short_sales' => 1,
                        'op_quality__client_num' => 1,
                    ]
                ],
                /*[
                    'header' => '',
                    'items' => [
                        'leads' => ['data' => $this->getLeads()],
                        'meets' => ['data' => $this->getMeets()],
                    ],
                ],*/
                [
                    'header' => 'Результативность Клиентского отдела (аккаунтинг)',
                    'items' => [
                        'ac_quality__trimester_sales' => 1,
                        /*'active_clients' => ['data' => $activeClients],
                        'payment' => ['data' => $payments],*/
                    ]
                ],
                [
                    'header' => 'Доп. аналитика: Объемы продаж в разрезе по продуктам',
                    'items' => [

                        'md_sales_adv' => 1,
                        'md_sales_seo' => 1,
                        'md_sales_cro' => 1,
                        //'md_sales_sites' => 1,
                        'md_sites' => 1,
                        'md_supp_sites' => 1,
//        				'md_sales_support' => 1,
                        'md_sales_other' => 1,
                        'md_host_domains' => 1,
                        'md_bitrix_24' => 1,
                        'md_sales_devel' => 1,
                    ]

                ],
                [
                    'header' => 'Прочие графики',
                    'items' => [
                        'new_long_sales_val' => 1,
                        'new_long_midi' => 1,
                        'new_long_midi_by_comp' => 1,
                        'struct_new_sales_city' => 1,
                        'summ_long_sales_by_check' => 1,
                        'number_companies_by_check' => 1,
                        'average_check_by_companies' => 1
                    ]

                ]


//         	'sales_val' => 1,
//         	'struct_sales_vd' => 1,
//         	'struct_sales_city' => 1,


//         	'new_long_sales_val' => 1,
//            	'new_long_sales' => 1,
//         	'struct_new_sales_city' => 1,


//        		'new_long_midi' => 1,
//        		'leads' => array(),
//         	'meets' => array(),


//         	'payment' => array(),

//         	'md_sales_adv' => 1,
//         	'md_sales_seo' => 1,
//         	'md_sales_sites' => 1,
//         	'md_sales_support' => 1,
//         	'md_sales_other' => 1,

//         	'active_clients' => array(),
//         	'total_long_sales' => 1,
//         	'new_long_12_weeks' => 1,

//         	'struct_sales_new' => 1,
//         	'new_long_sales_md' => 1,

//         	'op_quality__long_sales' => 1,
//         	'op_quality__short_sales' => 1,

//         	'leads__weekly_by_types' => 1,

//         	'ac_quality__trimester_sales' => 1,


            );

      
//         /**
//          * Получаем Актвиных клиентов
//          */
//         $result['active_clients'] = $this->getActiveClients($monthsLimits);
//         /**
//          * Получаем поступление оплат
//          */
//         $result['payment'] = $this->getPayment();
//         /**
//          * Получаем сделки
//          */
//         $result['leads'] = $this->getLeads();
//         /**
//          * Получаем встречи
//          */
//         $result['meets'] = $this->getMeets();
        
        
        $this->result = $result;
        return $result;
    }

    

    
    
    protected function getMidi($sales, $md, $vd){
        $result = array(
            'month' => array(),
            'data' => array(
                array(
                	'name' => 'Средний чек нов. дл. продаж МД-15',
                	'data' => array(),
                ),
            	array(
            		'name' => 'Средний ВД нов. дл. продаж',
            		'data' => array(),
            	),
            ),
        );
        foreach ($sales['data']['data'] as $key => $item) {
            $result['month'][] = $sales['month'][$key];
            
            $result['data'][0]['data'][$key] = (float)number_format($sales['data']['data'][$key] == 0 ? 0 : $md['data'][0]['data'][$key] / $sales['data']['data'][$key],2,'.','');
            $result['data'][1]['data'][$key] = (float)number_format($sales['data']['data'][$key] == 0 ? 0 : $vd['data'][0]['data'][$key] / $sales['data']['data'][$key],2,'.','');
        }
        return $result;
    }
    /**
     * @return array
     */
    protected function getStructSales()
    {
        $this->sales = $this->getQuerySales($this->monthLimits['min'], $this->monthLimits['max']);
        $saleRes = array();
        $saleResVZ = array();
        
        foreach ($this->sales as $item) {

        	if (!isset($saleRes[$item->FK_control_month])) {
        		$saleRes[$item->FK_control_month] = array(
        				'long' => 0,
        				'alone' => 0,
        		);
        		$saleResVZ[$item->FK_control_month] = 0;
        	}
        	
            
            foreach ($item->products as $product) {
                $saleResVZ[$item->FK_control_month] += $this->culcVZ($product);
                if ($product->elaboration->is_long) {
                        $saleRes[$item->FK_control_month]['long'] += $this->culcMD15($product);
                } else {
                        $saleRes[$item->FK_control_month]['alone'] += $this->culcMD15($product);
                }
            }
            

        }
        $result = array(
            'month' => array(),
            'data' => array(
                array(
                    'name' => 'Разовые',
                    'data' => array(),
                ),
                array(
                    'name' => 'Длинные',
                    'data' => array(),
                )

            ),
        );
        $resultVZ = array(
            'month' => array(),
            'data' => array(
                array(
                    'name' => 'Объем валового дохода',
                    'data' => array(),
                )

            ),
        );
        
        ksort( $saleRes);
        ksort( $saleResVZ);
        
        foreach ($saleRes as $key => $item) {
                   	
            
            $result['month'][] = Month::getMonthName($key,1);
            $result['data'][1]['data'][] = $item['long'];
            $result['data'][0]['data'][] = $item['alone'];
            
            $resultVZ['data'][0]['data'][] = $saleResVZ[$key];
            $resultVZ['month'][] = Month::getMonthName($key,1);
        }
        return [$result,$resultVZ];
    }

    protected function getQuerySales($start, $end)
    {
        $criteria = new CDbCriteria();
        $criteria->with = ['products', 'products.elaboration'];
        $criteria->addCondition('`t`.FK_sale_state != 3');
        $criteria->addCondition('`t`.active = 1');
        $criteria->addCondition('`t`.FK_control_month >= ' . $start);
        $criteria->addCondition('`t`.FK_control_month <= ' . $end);
        $criteria->join = 'LEFT JOIN `t_sales_summaries` `smms` ON `smms`.FK_sale = `t`.ID';
        $criteria->order = '`t`.FK_control_month';  // для сравнения с прошлым месяцем в один пробег ((
        
        $criteria->addCondition('
        		( `t`.W_lock = "Y" ) OR 
        		(`t`.FK_control_month = ' . $end . ' AND `smms`.payed >= `smms`.total * 0.1)');
        
        $criteria->distinct = true;
        	
        return Sale::model()->findAll($criteria);
    }

    protected function culcMD15($products)
    {
        try {
            $md = @((1 - ($products->outlay2 + $products->discost) / 100) * $products->total / (1 - $products->discost / 100));
        }catch (Exception $e){
            $md = 0;
        }
        return $md;
    }
    protected function culcVZ($products){
        try {
            $vz = @((1 - ($products->outlay_VZ + $products->discost) / 100) * $products->total / (1 - $products->discost / 100));
        }catch (Exception $e){
            $vz = 0;
        }
        return $vz;
        
        
    }

    /**
     * @return array
     */
    protected function getMeets()
    {
        //date
        $meets = $this->getQueryMeets($this->startDate->format('Y-m-d'), $this->endDate->format('Y-m-d h:i:s'));
        $result = array(
            'month' => array(),
            'data' => array(
                array(
                    'name' => 'Проведено встреч',
                    'data' => array(),
                )
            ),
        );
        $meetsRes = array();
        foreach ($meets as $meet) {
            $date = new DateTime($meet->close_time);
            if (isset($meetsRes[$date->format('F-Y')])) {
                $meetsRes[$date->format('F-Y')]++;
            } else {
                $meetsRes[$date->format('F-Y')] = 1;
            }
        }
        $result['month'] = array_keys($meetsRes);
        $result['data'][0]['data'] = array_values($meetsRes);
        return $result;

    }

    /**
     * @param $start
     * @param $end
     * @return static[]
     */
    protected function getQueryMeets($start, $end)
    {
        $criteria = new CDbCriteria();
        $criteria->addCondition('FK_task_type = 1');
        $criteria->addCondition('W_lock = "Y"');
        $criteria->addBetweenCondition('close_time', $start, $end);
        $criteria->order = "close_time ASC";
        return Task::model()->findAll($criteria);
    }

    /**
     * @return array
     */
    protected function getLeads()
    {
        //date
        $ch = curl_init('https://api.iqcrm.net/?ajax_action=get_deals&dateStart='
            . $this->startDate->format('Y-m-d') . '&dateEnd=' . $this->endDate->format('Y-m-d').'T'.date('H:i:s'));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        $leads = json_decode(curl_exec($ch));
        curl_close($ch);

        $result = array(
            'month' => array(),
            'data' => array(
                array(
                    'name' => 'Создано сделок',
                    'data' => array(),
                )
            ),
        );
        $leadsRes = array();

        if (!empty($leads->result))
            foreach ($leads->result as $lead) {
                $date = new DateTime($lead->DATE_CREATE);
                if (isset($leadsRes[$date->format('F-Y')])) {
                    $leadsRes[$date->format('F-Y')]++;
                } else {
                    $leadsRes[$date->format('F-Y')] = 1;
                }
            }
        $result['month'] = array_keys($leadsRes);
        $result['data'][0]['data'] = array_values($leadsRes);
        return $result;
    }

    /**
     * @param $start
     * @param $end
     * @return static[]
     */
    protected function getQueryLeads($start, $end)
    {
        $criteria = new CDbCriteria();
        $criteria->addBetweenCondition('add_date', $start, $end);
        $criteria->order = "add_date ASC";
        return Lead::model()->findAll($criteria);
    }

    /**
     * @param $monthsLimits
     * @return array
     */
    protected function getActiveClients($monthsLimits)
    {
        $activ = $this->getQueryActiveClients($monthsLimits);

        $result = array(
            'month' => array(),
            'data' => array(
                'name' => 'Активные клиенты',
                'data' => array()
            ),
        );
        //инициалтзируем итоговый массив
        $activeClients = array();
        for ($i = $this->monthLimits['min'] - 1; $i < $this->monthLimits['max']; $i++) {
            $activeClients[$i + 1] = array();
        }

        foreach ($activ as $item) {
            //Если смотрим не в последний месяц
            if ($item['FK_control_month'] != $this->monthLimits['max']) {
                //то проверяем на закрытость продажи
                if ($item['W_lock'] == "Y") {
                    $activeClients[$item['FK_control_month']][] = $item['FK_company'];
                }
            } else {
                //В последнем месяце без разницы, закрыта или оплачена
                $activeClients[$item['FK_control_month']][] = $item['FK_company'];
            }

        }
        foreach ($activeClients as $activeClient) {
            $result['data']['data'][] = count($activeClient);
        }
        //получаем название месяцев
        foreach ($activeClients as $key => $item) {
        
            $result['month'][] = Month::getMonthName($key, 1);
        }
        return $result;
    }

    /**Получаем все продажи в периоде, за исключением Хостингов и доменов
     * @param $monthsLimits
     * @return mixed
     */
    protected function getQueryActiveClients($monthsLimits)
    {
        //Общая часть
        $DS = Yii::app()->db->createCommand()
            ->select('S.FK_company,
            S.FK_control_month,
            S.W_lock')
            ->from('{{services_provided}} servprod')
            ->join('{{sales_products}} P', 'P.ID = servprod.FK_sale_product')
            ->join('{{sales}} S', 'P.FK_sale = S.ID')
            ->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
            ->join('{{sales_summaries}} smms', ' smms.FK_sale = S.ID');


        $DS->where('P.ID > 0')
            ->andWhere('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('S.FK_sale_state != 3')
            ->andWhere('P.FK_sale_product_kind != 17')
            ->andWhere('P.FK_sale_product_kind != 19')
            ->order('S.FK_control_month')
            ->group('S.FK_control_month, S.FK_company, S.W_lock');


        $DS->andWhere('S.FK_control_month >= ' . ($monthsLimits ['min']));
        $DS->andWhere('S.FK_control_month <= ' . ($monthsLimits ['max']));
        $DS->andWhere('(S.W_lock = "Y" OR ((smms.payed - smms.total) >= 0))');


        $data = $DS->queryAll();


        return $data;
    }

    /**
     * @return array
     */
    protected function getPayment()
    {
        //Получаем все оплаты за период
        $payment = $this->getQueryPayment($this->startDate->format('Y-m-d'), $this->endDate->format('Y-m-d'));
        $summ1 = 0;
        $summ2 = 0;
        $result = array(
            'month' => array(),
            'data' => array(
                array(
                    'type' => 'line',
                    'name' => 'Поступление оплат, руб.',
                    'data' => array(),
                    'marker' => array(
                        'enabled' => true
                    ),
                ),
                array(
                    'type' => 'scatter',
                    'name' => 'Среднее',
                    'data' => array()
                ),

            ),
        );
        $paymentMonths = array();
        foreach ($payment as $item) {
            $date = new DateTime($item->payment_date);
            //Считаем среднее за последние два месяца
            if ($this->endDate->format('m') - $date->format('m') <= 2 &&
                $this->endDate->format('m') - $date->format('m') > 1 &&
                (int)$this->endDate->format('d') >= (int)$date->format('d')
            ) {
                $summ1 += $item->summ;
            }
            if ($this->endDate->format('m') - $date->format('m') <= 1 &&
                $this->endDate->format('m') - $date->format('m') > 0 &&
                $this->endDate->format('d') >= $date->format('d')
            ) {
                $summ2 += $item->summ;
            }
            if (isset($paymentMonths[$date->format('F-Y')])) {
                $paymentMonths[ $date->format('F-Y')] += $item->summ;
            } else {
                $paymentMonths[$date->format('F-Y')] = $item->summ;
            }
        }
        foreach ($paymentMonths as $month => $paymentMonth) {
            $result['month'][] = $month;
            $result['data'][0]['data'][] = $paymentMonth;
        }
        //получаем среднее за последние два месяца на текущей день месяца
        $result['data'][1]['data'] = $result['data'][0]['data'];
        $result['data'][1]['data'][count($result['data'][1]['data']) - 1] = ($summ1 + $summ2) / 2;

        return $result;
    }

    /**
     * @param string $startDate Y-m-d
     * @param string $endDate Y-m-d
     * @return static[]
     */
    protected function getQueryPayment($startDate, $endDate)
    {
        $criteria = new CDbCriteria();
        $criteria->addBetweenCondition("payment_date", $startDate, $endDate);
        //$criteria->with(['lines']);
        $criteria->join = 'left join {{payments_parts}} as pp ON pp.FK_payment = t.ID
                           left join {{sales}} s ON s.ID = pp.FK_sale
                           left join {{sales_products}} as sap ON sap.FK_sale = s.ID
                           left join {{services_provided}} as sp ON sp.FK_sale_product = sap.ID';
        $criteria->addCondition('sp.id IS NOT NULL');
        $criteria->order = "payment_date ASC";
        $payment = Payment::model()->findAll($criteria);
        return $payment;
    }
    protected function getTotalLongSales($monthsLimits){
        $result = array(
            'month' => array(),
            'data' => array(
                array(
                    'name' => 'Общее кол-во длинных продаж',
                    'data' => array()
                )
            )
        );
        $total = $this->getQueryLongSales($monthsLimits, false);
        //инициалтзируем итоговый массив
        $totalRes = array();
        for ($i = $this->monthLimits['min'] - 1; $i < $this->monthLimits['max']; $i++) {
            $totalRes[$i + 1] = 0;
        }
        foreach ($total as $item) {
            if (isset($totalRes[$item['FK_control_month']])) {
                $totalRes[$item['FK_control_month']]++;
            } else {
                $totalRes[$item['FK_control_month']] = 1;
            }
        }
        //получаем название месяцев
        foreach ($totalRes as $key => $item) {
            
            $result['month'][] = Month::getMonthName($key, 1);
        }
        $result['data'][0]['data'] = array_values($totalRes);
        return $result;
    }
    /**
     * @param $monthsLimits
     * @return array
     */
    protected function getLongSales($monthsLimits)
    {
        //Получаем все за 15 месяцев до периода
        $first = $this->calcFirstTimesNewLongSales($monthsLimits);
        //Получаем в периоде
        $new = $this->getQueryLongSales($monthsLimits, true);
        
        $companies = array();
        
        //получаем в последнем месяце, закрытые + оплаченные

        //Прореживаем первые 5 мес, относительно тех, что уже были
        foreach ($first as $key => $item) {
            foreach ($new as $key2 => $value) {
                if ($first[$key]['FK_company'] == $new[$key2]['FK_company'] &&
                    $first[$key]['group'] == $new[$key2]['group'] &&
                    abs($new[$key2]['FK_control_month'] - $first[$key]['FK_control_month']) < 12
                ) {
                    $new[$key2]['delete'] = true;
                }
            }
        }
        foreach ($new as $key => $item) {
            foreach ($new as $key2 => $item2) {
                if($item['group'] == $item2['group'] &&
                    $item['FK_company'] == $item2['FK_company'] &&
                    $item['FK_control_month'] < $item2['FK_control_month'] &&
                    abs($item['FK_control_month'] - $item2['FK_control_month']) < 12
                ){
                    $new[$key2]['delete'] = true;
                }
            }
        }
        foreach ($new as $key => $item) {
            if(isset($item['delete']) && $item['delete']){
                unset($new[$key]);
            }else{
                $companies[] = $item['FK_company'];
                $new[$key]['old'] = false;
            }
        }
        //смотрим продажи по компаниям за год до периода, что бы выявить старые
        $companies = array_unique($companies);
        $companiesRes = $this->getSalesForCompanies($companies,$monthsLimits['min'] - 12, $monthsLimits['min']-1);
        foreach ($new as $key => $item) {
            if(isset($companiesRes[$item['FK_company']])){
                $new[$key]['old'] = true;
            }
        }
        foreach ($new as $item) {
            foreach ($new as $key => $value) {
                if($item['FK_company'] == $value['FK_company'] && $item['ID'] != $value['ID']){
                    if($value['FK_control_month'] - $item['FK_control_month'] < 12 && $value['FK_control_month'] - $item['FK_control_month'] > 0){
                        $new[$key]['old'] = true;
                    }
                }
            }
        }
        //инициалтзируем итоговый массив
        $arMD = array();
        $arMDOld = array();
        $arVZ = array();
        $newRes = array();
        $new12month = array(
            'label' => array(),
            'dates' => array(),
            'data' => array()
        );
        for ($i = $this->monthLimits['min'] - 1; $i < $this->monthLimits['max']; $i++) {
            $newRes[$i + 1] = 0;
            $arMD[$i + 1] = 0;
            $arVZ[$i + 1] = 0;
        }
        $current = new DateTime();
        $int = new DateInterval("P1W");
        $int->invert = 1;
        for ($i = 0; $i < 12; $i++){
            $mond = new DateTime($current->format("d-m-Y"));
            $sund = new DateTime($current->format("d-m-Y"));
            $current->add($int);
            $mond->modify('monday this week');
            $sund->modify('sunday this week');
            $new12month['label'][] = $mond->format('d.m-').$sund->format("d.m");
            $new12month['data'][] = 0;
            $new12month['dates'][] = array("mond" => $mond, "sund" => $sund);
        }
        //подсчитываем кол-во по месяцам
        $MD = array(
            'month' => array(),
            'data' => array(
                array(
                    'name' => 'Сумма нового МД-15 по новым длинным продуктам',
                    'data' => array()
                ),
                array(
                    'name' => 'Сумма нового МД-15 по новым д.п. по старым клиентам',
                    'data' => array()
                ),
            )
        );
        $VZ = array(
            'month' => array(),
            'data' => array(
                array(
                    'name' => 'Сумма нового вал. дохода по нов. дл. продуктам',
                    'data' => array()
                )
            )
        );


        foreach ($new as $item) {
            if (isset($newRes[$item['FK_control_month']])) {
                $newRes[$item['FK_control_month']]++;
            } else {
                $newRes[$item['FK_control_month']] = 1;
            }
            if($item['old']){
                if (isset($arMDOld[$item['FK_control_month']])) {
                    $arMDOld[$item['FK_control_month']] += $item['MD'];
                } else {
                    $arMDOld[$item['FK_control_month']] = (int)$item['MD'];
                }
            }else {
                if (isset($arMD[$item['FK_control_month']])) {
                    $arMD[$item['FK_control_month']] += $item['MD'];
                } else {
                    $arMD[$item['FK_control_month']] = (int)$item['MD'];
                }
            }
            if (isset($arVZ[$item['FK_control_month']])) {
                $arVZ[$item['FK_control_month']] += $item['VZ'];
            } else {
                $arVZ[$item['FK_control_month']] = (int)$item['VZ'];
            }
            $date = new DateTime($item['sale_date']);
            foreach ($new12month['dates'] as $key => $val) {
                if($date >= $val['mond'] && $date <= $val['sund']){
                    $new12month['data'][$key]++;
                }
            }
        }
        //структура итогового массива
        $result = array(
            'month' => array(),
            'data' => array(
                'name' => '',
                'data' => array()
            )
        );
        //Продажи за 12 недель
        $result12 = array(
            'labels' => array_reverse($new12month['label']),
            'data' => array(
                'name' => 'Новые длинные продажи',
                'data' => array_reverse($new12month['data'])
            )
        );
        //получаем название месяцев
        foreach ($newRes as $key => $item) {
            
            $result['month'][] = Month::getMonthName($key, 1);
        }
        //отдаем
        $MD['month'] = $result['month'];
        $MD['data'][0]['data'] = array_values($arMD);
        $MD['data'][1]['data'] = array_values($arMDOld);
        $VZ['month'] = $result['month'];
        $VZ['data'][0]['data'] = array_values($arVZ);
        $result['data']['name'] = 'Длинные продажи';
        $result['data']['data'] = array_values($newRes);
        return [$result,$MD,$result12,$VZ];
    }

    /**
     * @param $monthsLimits
     * @return mixed
     */
    protected function getQueryLongSales($monthsLimits, $new = false)
    {
        //Общая часть
        $DS = Yii::app()->db->createCommand()
            ->select('S.FK_company,
            (P.FK_sale_product_kind * 1000 + E.FK_feature1) AS `group`,
            S.FK_control_month,
            S.FK_manager,
            P.FK_sale_product_kind,
            E.FK_feature1,
            E.FK_feature2,
            S.ID,
            E.is_long,
            S.sale_date,
            (1-(P.outlay2+P.discost)/100) * P.total/(1-P.discost/100) as MD2,
            sum((1-(P.outlay2+P.discost)/100) * P.total/(1-P.discost/100)) as MD,
            sum((1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)) as VZ')
            ->from('{{sales_products}} P')
            ->join('{{sales}} S', 'P.FK_sale = S.ID')
            ->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
            ->join('{{sales_summaries}} smms', ' smms.FK_sale = S.ID');;


        $DS->where('P.ID > 0')
            ->andWhere('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('S.FK_sale_state != 3')
            ->order('S.FK_control_month');
        if ($new) {
            $DS->group('S.FK_company, group, S.FK_control_month');
        }else{
            $DS->group('S.ID');
        }

        $DS->andWhere('E.is_long = 1');
        $DS->andWhere('S.FK_control_month >= ' . ($monthsLimits ['min']));
        $DS->andWhere('S.FK_control_month <= ' . ($monthsLimits ['max']));
        $DS->andWhere("((S.FK_control_month < " . ($monthsLimits ['max']) . ") AND (S.W_lock = 'Y') OR ((S.FK_control_month = " . ($monthsLimits ['max']) . ") AND (S.W_lock = 'Y' OR smms.payed - smms.total) >= 0))");

        $data = $DS->queryAll();


        return $data;
    }

    /**
     * @param $monthsLimits
     * @return mixed
     */
    private function calcFirstTimesNewLongSales($monthsLimits)
    {
        //Общая часть
        $DS = Yii::app()->db->createCommand()
            ->select('S.FK_company,
            (P.FK_sale_product_kind * 1000 + E.FK_feature1) AS `group`,
            S.FK_control_month,
            S.FK_manager,
            P.FK_sale_product_kind,
            E.FK_feature1,
            S.FK_sale_state,
            S.ID,
            E.is_long')
            ->from('{{sales_products}} P')
            ->join('{{sales}} S', 'P.FK_sale = S.ID')
            ->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID');


        $DS->where('P.ID > 0')
            ->andWhere('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('S.W_lock = "Y"')
            ->andWhere('S.FK_sale_state != 3')
            ->order('S.FK_control_month')
            ->group('S.FK_company, group, S.FK_control_month ');

        $DS->andWhere('E.is_long = 1');

        $DS->andWhere('S.FK_control_month >= ' . ($monthsLimits ['min'] - 12));
        $DS->andWhere('S.FK_control_month < ' . ($monthsLimits ['min']));


        $data = $DS->queryAll();


        return $data;

    }


    
    public function formatSaleChanges(  )
    {
    	$monthsLimits = $this->monthLimits;
    	
    	
    	// первые разы в периоде
    	$firstTimesInPeriod = $this->_firstSaleForClient($monthsLimits['min'], $monthsLimits['max']);
    	    	
    	// последние разы до периода
    	$lastTimesBeforePeriod = $this->_lastSaleForClient($monthsLimits['min']-19, $monthsLimits['min']-1);
    	
    	
    	$features = CHtml::listData(Features::model()->findAll( 'active > 0' ), 'ID', 'feature1');
    	$monthIds = Month::getMonthNameList($monthsLimits['min'] -1 , $monthsLimits['max']);
    	
		$R = [];
		$distr = [];
		
		foreach ($features as $fid=>$fname)
		{
			$TR = [];
			foreach ($monthIds as $mid=>$mname)
			{
				$TR[ $mid ] = 0;
			}
			$R[ $fid ] = [
            	'new' => $TR,
            	'returns' => $TR,
            	'lost' => $TR,
            	
			]; 
		}				
    			
		
		foreach ( $this->sales as $sale )
		{
			foreach ( $sale->products as $product )
			{
				//1.totals
				if( !isset ($distr[ $sale->FK_control_month][ $product->elaboration->FK_feature1 ][ $sale->FK_company ]))
					$distr[ $sale->FK_control_month][ $product->elaboration->FK_feature1 ][ $sale->FK_company ] = 0;
				$distr[ $sale->FK_control_month][ $product->elaboration->FK_feature1 ][ $sale->FK_company ] += $this->culcVZ($product);
				
				//1.news
				if( 
					($sale->FK_control_month == $firstTimesInPeriod[ $sale->FK_company ][ $product->elaboration->FK_feature1]) &&
					( !isset($lastTimesBeforePeriod[ $sale->FK_company ][ $product->elaboration->FK_feature1]) ||
							$lastTimesBeforePeriod[ $sale->FK_company ][ $product->elaboration->FK_feature1]-$firstTimesInPeriod[ $sale->FK_company ][ $product->elaboration->FK_feature1] > 15 
					))	
					{
						
						$R[ $product->elaboration->FK_feature1 ][ 'new' ][ $sale->FK_control_month ] += $this->culcVZ($product);	
					}
				
			}
		}

		// $distr[ $sale->FK_control_month][ $product->elaboration->FK_feature1 ][ $sale->FK_company ]
		foreach ( $distr as $mid => $mid_data)
		{
			foreach ( $mid_data as $fid => $fid_data )
			{
				foreach ( $fid_data as $cid => $cid_data )
				{
					if( $mid > $monthsLimits['min'])
					{
						if( !isset( $distr[ $mid-1][ $fid ][ $cid ] ))
							$R[ $fid ][ 'returns' ][ $mid ] += $cid_data;
					}

					if( !isset( $distr[ $mid+1][ $fid ][ $cid ] ) && isset($R[ $fid ][ 'lost' ][ $mid+1 ])) 
						$R[ $fid ][ 'lost' ][ $mid+1 ] += $cid_data;
						
				}
			}
		}	
				
		
		foreach ($features as $fid=>$fname)
		{
			unset( $R[$fid]['new'][$monthsLimits['min'] -1]);
			unset( $R[$fid]['returns'][$monthsLimits['min'] -1]);
			unset( $R[$fid]['lost'][$monthsLimits['min'] -1]);
			
			unset( $R[$fid]['new'][$monthsLimits['max'] +1]);
			unset( $R[$fid]['returns'][$monthsLimits['max'] +1]);
			unset( $R[$fid]['lost'][$monthsLimits['max'] +1]);
		}	

		$series1 = [];
		foreach ($features as $fid=>$fname)
		{
			$series1[] = array(
				'name' 	=> $fname.'.Нов',
				'data'	=>	array_values( $R[$fid]['new'] ),
				'stack' => 'new',
			);
		}
		
		$series2 = [];
		foreach ($features as $fid=>$fname)
		{	
			$series2[] = array(
					'name' 	=> $fname.'.Возв',
					'data'	=>	array_values( $R[$fid]['returns'] ),
					'stack' => 'returns',
			);
		}
		
		$series3 = [];
		foreach ($features as $fid=>$fname)
		{
			$series3[] = array(
					'name' 	=> $fname.'.Птр',
					'data'	=>	array_values( $R[$fid]['lost'] ),
					'stack' => 'lost',
			);
			 
		}
		
		return [ $series1, $series2, $series3]; 
		
    }
    
    public function monthList($full = false)
    {
        if ($full) {
            return Month::getMonthNameList(
                $this->monthLimits['max'] - 16,
                $this->monthLimits['max']
            );
        } else {
            return array_values(Month::getMonthNameList(
                $this->monthLimits['min'],
                $this->monthLimits['max']
            ));
        }
    }
    
    /**
     * Первый раз клиент за период
     * @param unknown $m1
     * @param unknown $m2
     *
     * @return [ Company ][ Elab_Feature1 ] =  MonthID 
     */
    private function _firstSaleForClient( $m1, $m2)
    {
    	$result = [];
    
    	$DS = Yii::app()->db->createCommand();
    	$DS->select( 'min(S.FK_control_month) as M, C.ID as C, E.FK_feature1 as F1')
    		->from('{{sales}} S')
    		->join('{{companies}} C', 'S.FK_company = C.ID')
    		->join('{{sales_products}} P', 'P.FK_sale = S.ID')
    		->join('{{spk_elaborations}} E', 'E.ID = P.FK_sale_prod_elaboration')
    		 
    		->where('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('S.W_lock = "Y" or S.FK_control_month = ' . $this->monthLimits['max'])
            ->andWhere('S.FK_sale_state != 3')
            ->andWhere('S.FK_control_month >= '.$m1)
            ->andWhere('S.FK_control_month <= '.$m2)
                		
    		->group( 'C, F1');
    	
    
    	$rows2 = $DS->queryAll();
    
    		foreach( $rows2 as $line )
    		{
    			if( !isset( $result [ $line['C']] ) ) $result [ $line['C']] = array();
    			
    			$result [  $line['C'] ][ $line['F1'] ] = $line['M'];
    		}
    	
    	    		
    	return $result;
    }
    																																					
    private function _lastSaleForClient( $m1, $m2)
    {
    	$result = [];
    
    	$DS = Yii::app()->db->createCommand();
    	$DS->select( 'max(S.FK_control_month) as M, C.ID as C, E.FK_feature1 as F1')
    		->from('{{sales}} S')
    		->join('{{companies}} C', 'S.FK_company = C.ID')
    		->join('{{sales_products}} P', 'P.FK_sale = S.ID')
    		->join('{{spk_elaborations}} E', 'E.ID = P.FK_sale_prod_elaboration')
    		 
    		->where('P.active > 0')
    		->andWhere('S.active > 0')
    		->andWhere('S.W_lock = "Y"')
    		->andWhere('S.FK_sale_state != 3')
    		->andWhere('S.FK_control_month >= '.$m1)
    		->andWhere('S.FK_control_month <= '.$m2)
    		
    		->group( 'C, F1');
    	
    
    	$rows2 = $DS->queryAll();
    
    	
    		foreach( $rows2 as $line )
    		{
    			if( !isset( $result [ $line['C']] ) ) $result [ $line['C']] = array();
    			 
    			$result [  $line['C'] ][ $line['F1'] ] = $line['M'];
    		}
    	
    	 
    	return $result;
    }

    private function getSalesForCompanies($companies, $m1, $m2){
        $result = [];

        $DS = Yii::app()->db->createCommand();
        $DS->select( 'S.ID as S_ID, S.FK_control_month as month, C.ID as C')
            ->from('{{sales}} S')
            ->join('{{companies}} C', 'S.FK_company = C.ID')
            ->join('{{sales_products}} P', 'P.FK_sale = S.ID')
            ->join('{{spk_elaborations}} E', 'E.ID = P.FK_sale_prod_elaboration')

            ->where('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('S.W_lock = "Y"')
            ->andWhere('S.FK_sale_state != 3')
            ->andWhere('S.FK_company in ('.implode(', ', $companies ).')')
            ->andWhere('S.FK_control_month >= '.$m1)
            ->andWhere('S.FK_control_month <= '.$m2);

        $rows2 = $DS->queryAll();

        foreach( $rows2 as $line )
        {
            if( !isset( $result [ $line['C']] ) ) $result [ $line['C']] = array();

            $result [  $line['C'] ][ $line['month'] ] = true;
        }


        return $result;
    }
    
    
    public function getParameterList( $p, $isRop = false, $userId = false, $withDead = true)
    {
    	switch ( $p )
    	{    
    
    		case 'managers':
    			$mgrList = User::getManagersWithSales();
    			natsort($mgrList);

    			$asUser = Yii::app()->request->getParam('asUser');

    			if ($userId) {
                    $flwrList = User::getAllFollovers($userId);
                    return array_intersect($mgrList, $flwrList);
                }

                if (Yii::app()->user->id == 85 && !empty($asUser)) {
                    $flwrList = User::getAllFollovers($asUser);
                    return array_intersect($mgrList, $flwrList);

                } else {
                    //Костыль. Чумак(11) видит всех( 10/09/15) без подчинения
                    if ($id = Yii::app()->user->userIsAdmin || (Yii::app()->user->id == 11) || $isRop) {
                        return $mgrList;
                    } else {
                        $flwrList = User::getAllFollovers(Yii::app()->user->id, $withDead);
                        return array_intersect($mgrList, $flwrList);
                    }
                }
                break;

            case 'months':
                return $this->monthList(true);
                break;
    
    	
    
    		default:
    			return array();
    	}
    
    }

    public function getMonthStructure_OPLong($value, $strict = true, $mStart = false, $mEnd = false)
    {
        $field = $this->getField($value);
        $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_SALEMGR);

        if ($mStart) {
            $this->mStart = $mStart;
        }
        else {
            $this->mStart = $this->monthStart;
        }
        if ($mEnd) {
            $this->mEnd = $mEnd;
        }
        else {
            $this->mEnd = $this->monthEnd;
        }

        //убираем Иру и Валю!
        foreach ($mpp_list as $k => $mgr) {
            if (in_array($mgr, [11])) {
                unset($mpp_list[$k]);
            }
            //Валя только для внутреннего отображения
            if (Yii::app()->user->id == 386 && in_array($mgr, [82])) {
                unset($mpp_list[$k]);
            }
//            //Костыль для удаления Чудаевой Ани до апреля 2018
//            if ($this->monthEnd < 125 && in_array($mgr, [232])) {
//                unset($mpp_list[$k]);
//            }
        }

        $monthlyTops = [];
        /** 1. Максимумы помесячно!
         */
        $monthlyDS = $this->getBaseStructureQuery($this->mStart - 12, $this->mStart);
        $monthlyDS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
        // "длинные" - до периода
//        if ($strict) {
//            $this->lowStrict($monthlyDS);
//        } else {
//            $monthlyDS->andWhere('S.FK_control_month >=  (' . ($this->mStart - 1) . ')');
//        }
        $monthlyDS = $this->filterMarkedShorts($monthlyDS, false);
        $monthlyDS->select('S.FK_control_month AS M,
            S.FK_manager as U,
    		S.FK_company AS C, sum(' . $field . ') V')
            ->group('M, C');


        $rows = $monthlyDS->queryAll();
        foreach ($rows as $line)
        {
//            if(!isset($monthlyTops[$line['U']])) $monthlyTops[$line['U']] = [];
            if(!isset($monthlyTops[$line['C']])) $monthlyTops[$line['C']] = [];
            $monthlyTops[$line['C']][ $line['M']] = $line['V'];
        }

//////////////////////////////////////////////////////////////////////////////
        $context = $this->addContextTops($monthlyTops, $field);
        $firstContext = $context[0];
        $secondContext = $context[1];
/////////////////////////////////////////////////////////////////////

        $honeymoons = [];
        /** 2. По максимумам - точки начала льготных периодов
//         */
//        foreach ($monthlyTops as $U => $mTops)
            foreach ($monthlyTops as $C => $mData) {
                $toSave = false;
                $lastM = 0;
                $buff = [];
                foreach ($mData as $M => $V) {
                    if (!$toSave && ($M >= ($this->mStart - 2))) {
                        $toSave = true;
                    }
                    if (($M - $lastM) >= 12) {
                        $buff[$M] = 1;
                    }
                    $lastM = $M;
                }

                if ($toSave) {
                    $honeymoons[$C] = $buff;
                }
            }


//        foreach ($honeymoons as $U => $hMoons)
            foreach ($honeymoons as $C => $mData) {
                $buff = [];
                foreach ($mData as $M => $V) {
                    for ($i = 0; $i < 6; $i++) {
                        $buff[$M + $i] = 1;
                    }
                }
                $honeymoons[$C] = $buff;
            }



        /** 3. Все продажи. сохранить
         */


        $DS = $this->getBaseStructureQuery($this->mStart, $this->mStart);
        $DS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
        $this->filterMarkedShorts($DS, false);
        $DS->selectDistinct( "
    			
    			sum($field) as S,

    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
    			S.FK_company as C,
				S.FK_manager as U
    			")
            ->group('criteria, C, U');

        if ($strict) {
            $this->lowStrict($DS);
        } else {
            $DS->andWhere('S.FK_control_month =  (' . $this->mStart . ')');
        }
        $rows = $DS->queryAll();

        $R = [];
        foreach ($rows as $line)
        {
            if (!isset($R[$line['C']])) $R[$line['C']]  = [];
            if (!isset($R[$line['C']][$line['criteria']])) $R[$line['C']][$line['criteria']] = [
                'VD' => 0,
                'mgrs' => [],

            ];

            $R[$line['C']][$line['criteria']]['VD'] += $line['S'];
            $R[$line['C']][ $line['criteria']]['mgrs'][$line['U']] = 1;
        }


        /** 4. Прогоны по аналитике
         */
        $newbes = []; // Новые продажи
        $extens = []; // Расширения

        foreach ( $R as $C => $saleData)
        {
            $M = $this->mStart;

            $company = Company::model()->findByPk($C);
            $globMPP = $company->FK_manager;

            $monthVal = [];
            $monthValbyMPP = [];
            foreach($mpp_list as $mpp) {
                $monthVal[$mpp] = 0;
                $monthValbyMPP[$mpp] = 0;
            }
            $mpp = 0;

            // newblock
            foreach ($saleData as $criteria => $dataItem) {
                // замешан МПП
                $MPPused = false;
                foreach ($dataItem['mgrs'] as $mgr => $v) {
                    if (in_array($mgr, $mpp_list)) {
                        $MPPused = true;
                        $mpp = $mgr;
                        break;
                    }
                }


                // варианты
                // 1. Новая? Вот прям первая
                if (
                    ($this->isFirstSale($criteria, $C, $M)) &&
                    $MPPused
                ) {
                    if (
                        isset($honeymoons[$C][$M]) &&
                        !isset($honeymoons[$C][$M - 1])
                    ) {
                        if (!isset($newbes[$mpp])) $newbes[$mpp] = 0;
                        $newbes[$mpp] += $dataItem['VD'];

                        //иначе добавляем + к месячному приросту
                    } else {
                        $monthVal[$mpp] += $dataItem['VD'];
                        $monthValbyMPP[$mpp] += $dataItem['VD'];
                    }
                } else {
                    // точно - без новых!
                    if ($MPPused) {
                        $monthValbyMPP[$mpp] += $dataItem['VD'];
                        $monthVal[$mpp] += $dataItem['VD'];
                    } else {
                        if (!isset($monthVal[$globMPP])) $monthVal[$globMPP] = 0;

                        $monthVal[$globMPP] += $dataItem['VD'];
                    }

                }
            }
            // end of newblock


            // $monthVal  - объем за месяц
            // $monthValbyMPP - объем за месяц с помощью МПП
            // проверим, есть ли объемный прирост

            $best = 0;

            for ($shift = 1; $shift < 12; $shift++) {
                if (isset($monthlyTops[$C][$M - $shift])
                    && ($monthlyTops[$C][$M - $shift] > $best)
                ) {
                    $best = $monthlyTops[$C][$M - $shift];
                }
            }

            foreach($monthVal as $mpp => $mVal) {
                if (!in_array($mpp, $mpp_list)) {
                    if (!in_array($globMPP, $mpp_list)) continue;
                    $mpp = $globMPP;
                }

                //Для корректного расчета суммы допродажи после первой контекстной продажи
                $addContext = 0;
                if (isset($firstContext[$C][$M-1]) && !isset($secondContext[$C][$M]))
                    $addContext = $firstContext[$C][$M-1];
                elseif (isset($firstContext[$C][$M-1]) && isset($secondContext[$C][$M]) && $secondContext[$C][$M] < $firstContext[$C][$M])
                    $addContext += $firstContext[$C][$M-1] - $secondContext[$C][$M];

                $mVal += $addContext;


                if ($mVal > $best) //есть рост!!
                {
                    if (!isset($extens[$mpp])) {
                        $extens[$mpp] = 0;
                    }

                    // 1. Это льготный период?
                    if (isset($honeymoons[$C][$M])) {
                        $extens[$mpp] += $mVal - $best;
                    } else {
                        $extens[$mpp] += min($mVal - $best, $monthValbyMPP[$mpp]);
                    }

                }
            }
        }


        // Очистим данные вне периода и на выход
        $Rtrn = [
            [],	//new
            [],	//ext
        ];

        $Rtrn[0] = $newbes;
        $Rtrn[1] = $extens;

        return $Rtrn;
    }

    private function addContextTops( & $monthlyTops, $field)
    {
        $DS = $this->getBaseStructureQuery($this->mStart - 12, $this->mEnd);
        $DS = $this->filterMarkedShorts($DS, false);
        $DS->select('S.FK_control_month as M,
            S.FK_company as C,
            '.$field.' as V, 
            P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
            P.ID as PID');
        $rows2 = $DS->queryAll();

        //Для непотерь первого месяца по контекстным продажам
        //Берем первые продажи за год
        $DSin = $this->getBaseStructureQuery( $this->mStart - 12, $this->mEnd - 1 );
        $DSin->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
        $DSin->andWhere( 'E.is_long = 1' )->group( 'G, C');
        $DSin ->select('min( S.FK_control_month ) as M, S.FK_company  AS C, P.FK_sale_product_kind * 1000 + E.FK_feature1 AS G');

        $firstSales = [];
        $data2 = $DSin->queryAll();
        foreach ( $data2 as $line )
        {
            if ( !isset( $firstSales[ $line['G'] ] ))
                $firstSales[ $line['G'] ] = [];
            if ( !isset( $firstSales[ $line['G'] ][ $line['C'] ] ))
                $firstSales[ $line['G'] ][ $line['C'] ] = $line[ 'M' ];
        }

        //Ищем первые контекстные продажи за год
        $firstContext = [];
        foreach ($rows2 as $line) {
            //Если первая контекстная продажа
            if ( isset($firstSales[$line['criteria']][$line['C']]) && $firstSales[$line['criteria']][$line['C']] == $line['M'] && in_array(  $line['criteria'], [ 3002, 4002])
            ) {
                if (!isset($firstContext[$line['C']])) $firstContext[$line['C']][$line['M']] = 0;

                if ($line['V'] > 0)
                    $firstContext[$line['C']][$line['M']] += $line['V'];
            }
        }

        //Ищем контекстные продажи во втором месяце после первого
        $secondContext = [];
        foreach ($rows2 as $line) {
            //Если первая контекстная продажа была в предыдущем месяце
            if (isset($firstContext[$line['C']][$line['M'] - 1]) && in_array(  $line['criteria'], [ 3002, 4002])) {
                if (!isset($secondContext[$line['C']][$line['M']])) $secondContext[$line['C']][$line['M']] = 0;

                $secondContext[$line['C']][$line['M']] += $line['V'];
            }

        }

        //Коррекрируем $monthlyTops с учетом первых контекстных продаж
        foreach ($firstContext as $C => $data) {
            foreach ($data as $M => $S) {
                if (isset($secondContext[$C][$M+1]) && $secondContext[$C][$M+1] < $S)
                    //Если во втором месяце тоже были контекстные продажи и их сумма была меньше первого месяца, прибавим разницу
                    $monthlyTops[$C][$M+1] += $S - $secondContext[$C][$M+1];
                elseif (!isset($secondContext[$C][$M+1]))
                    $monthlyTops[$C][$M+1] += $firstContext[$C][$M];
            }
        }

        return [
            0 => $firstContext,
            1 => $secondContext
        ];
    }

    private function getAllLongSales($field) {
        $field = $this->getField($field);
        $DS = $this->getBaseStructureQuery($this->mStart, $this->mEnd);
        $DS = $this->filterMarkedShorts($DS, false);
        $DS->andWhere('Prf.FK_department != 6');
        $DS->select('S.FK_control_month as M,
            S.FK_company as C,
            '.$field.' as V, 
            P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
            P.ID as PID,
            S.ID as SID
            ');

        $rows = $DS->queryAll();
        return $rows;
    }

    public function salesByCheck($field, $type)
    {
        $rows = $this->getAllLongSales($field);
        $summs = [];
        foreach ($rows as $line) {
            if (!isset($summs[$line['M']][$line['C']])) {
                $summs[$line['M']][$line['C']]['summ'] = 0;
            }

            $summs[$line['M']][$line['C']]['summ'] += $line['V'];
            if ($line['V'] > 0)
                $summs[$line['M']][$line['C']]['dbg'][] = [
                    'V' => $line['V'],
                    'SID' => $line['SID'],
                    'C' => $line['C']
                ];
        }

        $result = [];
        foreach ($summs as $M => $companies) {
            foreach ($companies as $C => $S) {
                if ($S['summ'] <= 25000) {
                    if (!isset($result[0]['data'][$M]))
                        $result[0]['data'][$M] = 0;
                    switch ($type) {
                        case 'summ':
                            $result[0]['data'][$M] += $S['summ'];
                            break;
                        case 'companies' :
                            $result[0]['data'][$M] += 1;
                            break;
                    }

                    if ($type == 'companies') {
                        $result[0]['dbg'][$M][] = [
                            'C' => $C
                        ];
                    }
                    else {
                        if (!empty($S['dbg'])) {
                            foreach ($S['dbg'] as $item) {
                                $result[0]['dbg'][$M][] = [
                                    'V' => $item['V'],
                                    'SID' => $item['SID'],
                                    'C' => $item['C']
                                ];
                            }
                        }
                    }
                }
                elseif ($S['summ'] > 25000 && $S['summ'] <= 50000) {
                    if (!isset($result[1]['data'][$M]))
                        $result[1]['data'][$M] = 0;
                    switch ($type) {
                        case 'summ':
                            $result[1]['data'][$M] += $S['summ'];
                            break;
                        case 'companies' :
                            $result[1]['data'][$M] += 1;
                            break;
                    }

                    if ($type == 'companies') {
                        $result[1]['dbg'][$M][] = [
                            'C' => $C
                        ];
                    }
                    else {
                        if (!empty($S['dbg'])) {
                            foreach ($S['dbg'] as $item) {
                                $result[1]['dbg'][$M][] = [
                                    'V' => $item['V'],
                                    'SID' => $item['SID'],
                                    'C' => $item['C']
                                ];
                            }
                        }
                    }
                }
                else {
                    if (!isset($result[2]['data'][$M]))
                        $result[2]['data'][$M] = 0;
                    switch ($type) {
                        case 'summ':
                            $result[2]['data'][$M] += $S['summ'];
                            break;
                        case 'companies' :
                            $result[2]['data'][$M] += 1;
                            break;
                    }

                    if ($type == 'companies') {
                        $result[2]['dbg'][$M][] = [
                            'C' => $C
                        ];
                    }
                    else {
                        if (!empty($S['dbg'])) {
                            foreach ($S['dbg'] as $item) {
                                $result[2]['dbg'][$M][] = [
                                    'V' => $item['V'],
                                    'SID' => $item['SID'],
                                    'C' => $item['C']
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $result;
    }

    public function averageCheckByCompanies($field) {
        $rows = $this->getAllLongSales($field);
        $summs = [];
        foreach ($rows as $line) {
            if (!isset($summs[$line['M']][$line['C']]))
                $summs[$line['M']][$line['C']] = 0;

            $summs[$line['M']][$line['C']] += $line['V'];
        }

        $result = [];
        foreach ($summs as $M => $companies) {
            foreach ($companies as $C => $S) {
                if ($S <= 25000) {
                    if (!isset($result[0][$M]['summ'])) {
                        $result[0][$M]['summ'] = 0;
                        $result[0][$M]['count'] = 0;
                    }
                    $result[0][$M]['summ'] += $S;
                    $result[0][$M]['count'] += 1;
                }
                elseif ($S > 25000 && $S <= 50000) {
                    if (!isset($result[1][$M]['summ'])) {
                        $result[1][$M]['summ'] = 0;
                        $result[1][$M]['count'] = 0;
                    }
                    $result[1][$M]['summ'] += $S;
                    $result[1][$M]['count'] += 1;
                }
                else {
                    if (!isset($result[2][$M]['summ'])) {
                        $result[2][$M]['summ'] = 0;
                        $result[2][$M]['count'] = 0;
                    }
                    $result[2][$M]['summ'] += $S;
                    $result[2][$M]['count'] += 1;
                }
            }
        }

        $result2 = [];
        foreach ($result as $key => $data) {
            foreach ($data as $M => $values) {
                $result2[$key][$M] = $values['summ']/$values['count'];
            }
        }

        return $result2;

    }

    public function salesByMpp()
    {
        $long = $this->getLongSalesByMpp();
        $short = $this->getShortSalesByMpp();
        return ['long' => $long, 'short' => $short];
    }


    protected function getLongSalesByMpp()
    {
        //Получение длинных продаж
        $data = $this->getMonthlyStructure_OPLongByMpp('VD');

        //Преобразуем длинные продажи в массив в формат: [mgr => month => type => sum]
        $resultLong = [];
        foreach ($data[0] as $month => $new) {
            if ($new) {
                foreach ($new as $mgr => $sum) {
                    if ($sum > 0) $resultLong[$mgr][$month]['new'] = $sum;
                }
            }
        }

        foreach ($data[1] as $month => $new) {
            if ($new) {
                foreach ($new as $mgr => $sum) {
                    if ($sum > 0) $resultLong[$mgr][$month]['los'] = $sum;
                }
            }
        }

        foreach ($data[2] as $month => $new) {
            if ($new) {
                foreach ($new as $mgr => $sum) {
                    if ($sum > 0) $resultLong[$mgr][$month]['ext'] = $sum;
                }
            }
        }

        return $resultLong;
    }


    public function getShortSalesByMpp() {

        $DS = $this->getBaseStructureQuery();
        $DS = $this->filterManagersMPP($DS);

        $this->strict($DS);

        $DS->andWhere('(E.is_long = 0 or P.is_unique = 1)');
        $DS->select(
            $this->getField('VD') . ' AS S,
        S.FK_control_month AS M, 
        S.FK_manager as Mgr, 
        S.FK_company as cmp, 
        S.ID as sid,
        E.is_long as is_long,
        P.is_unique as is_unique,
        P.FK_sale_product_kind as kind')
            ->order('M, Mgr, cmp');

        $rows = $DS->queryAll();

        foreach ($rows as $line) {

            //Сайты
            if ($line['kind'] == 10) {
                if (!isset($result[$line['Mgr']][$line['M']]['site'])) $result[$line['Mgr']][$line['M']]['site'] = 0;
                $result[$line['Mgr']][$line['M']]['site'] += $line['S'];
            }
            //Доработки
            elseif ($line['kind'] == 16) {
                if (!isset($result[$line['Mgr']][$line['M']]['imp'])) $result[$line['Mgr']][$line['M']]['imp'] = 0;
                $result[$line['Mgr']][$line['M']]['imp'] += $line['S'];
            }
            //Битрикс24
            elseif  ($line['kind'] == 67) {
                if (!isset($result[$line['Mgr']][$line['M']]['b24'])) $result[$line['Mgr']][$line['M']]['b24'] = 0;
                $result[$line['Mgr']][$line['M']]['b24'] += $line['S'];
            }
            //Домены и Хостинги
            elseif  ($line['kind'] == 17 || $line['kind'] == 19) {
                if (!isset($result[$line['Mgr']][$line['M']]['host'])) $result[$line['Mgr']][$line['M']]['host'] = 0;
                $result[$line['Mgr']][$line['M']]['host'] += $line['S'];
            }
            //Прочие
            else {
                if (!isset($result[$line['Mgr']][$line['M']]['other'])) $result[$line['Mgr']][$line['M']]['other'] = 0;
                $result[$line['Mgr']][$line['M']]['other'] += $line['S'];
            }

        }

        return $result;

    }



    public function getMonthlyStructure_OPLongRop( $value, $strict = true)
    {
        $field = $this->getField($value);
        $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList( CrmAccessDispatcher::GROUP_SALEMGR );

        //убираем Иру и Валю!
        foreach ($mpp_list as $k => $mgr) {
            if (in_array($mgr, [11])) {
                unset($mpp_list[$k]);
            }
            //Валя только для внутреннего отображения
            if (Yii::app()->user->id == 386 && in_array($mgr, [82])) {
                unset($mpp_list[$k]);
            }
        }

        $monthlyTops = [];
        /** 1. Максимумы помесячно!
         */
        $monthlyDS = $this->getBaseStructureQuery( $this->mStart-12, $this->mEnd);

        $monthlyDS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!

        $monthlyDS = $this->filterMarkedShorts( $monthlyDS, false );
        $monthlyDS->select( 'S.FK_control_month AS M,
    		S.FK_company AS C, sum(' .$field . ') V')
            ->group( 'M,C');
        //$monthlyDS = $this->filterManagersMPP($monthlyDS);

        $rows = $monthlyDS->queryAll();
        foreach ( $rows as $line )
        {
            if( !isset( $monthlyTops[ $line['C']] )) $monthlyTops[ $line['C']] = [];
            $monthlyTops[ $line['C']][ $line['M']] = $line['V'];
        }

        //////////////////////////////////////////////////////////////////////////////
        $context = $this->addContextTops($monthlyTops, $field);
        $firstContext = $context[0];
        $secondContext = $context[1];
        /////////////////////////////////////////////////////////////////////

        $honeymoons = [];
        /** 2. По максимумам - точки начала льготных периодов
         */
        foreach ( $monthlyTops as $C => $mData)
        {
            $toSave = false;
            $lastM = 0;
            $buff = [];
            foreach ($mData as $M => $V)
            {
                if( !$toSave && ($M >= ($this->mStart - 2))) $toSave = true;
                if( ($M-$lastM) >= 12) $buff[$M] = 1;
                $lastM = $M;
            }

            if( $toSave )
                $honeymoons[ $C ] = $buff;
        }
        foreach ($honeymoons as $C=>$mData)
        {
            $buff = [];
            foreach ( $mData as $M=>$V)
            {
                for( $i = 0; $i<6; $i++)
                    $buff[ $M+$i ] = 1;
            }
            $honeymoons[ $C ] = $buff;
        }


        /** 3. Все продажи. сохранить
         */


        $DS = $this->getBaseStructureQuery( $this->mStart-12, $this->mEnd);
        $DS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
        $this->filterMarkedShorts($DS, false);
        $DS->selectDistinct( "
    			
    			sum($field) as S,

    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
    			S.FK_company as C,
    			S.FK_control_month as M,
    			E.ID as EID,
    			P.FK_sale_product_kind as PRK,
    			SK.FK_category as CTG,
				S.FK_manager as U
    			")
            ->group('M, C, U');

        if ($strict) {
            $this->strict($DS);
        } else {
            $DS->andWhere('S.FK_control_month >= (' . ($this->mEnd - 1) . ')');
        }
        $rows = $DS->queryAll();

        $R = [];
        foreach ($rows as $line)
        {
            if( !isset( $R[ $line[ 'C' ]])) $R[ $line[ 'C' ]]  = [];
            if( !isset( $R[ $line[ 'C' ]][ $line['M']] )) $R[ $line[ 'C' ]][ $line['M']]  = [];
            if( !isset( $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']] )) $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']] = [
                'VD' => 0,
                'mgrs' => [],

            ];


            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['EID'] = $line['EID'];
            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['PRK'] = $line['PRK'];
            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['CTG'] = $line['CTG'];

            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['VD'] += $line['S'];
            $R[ $line[ 'C' ]][ $line['M']][ $line['criteria']]['mgrs'][$line['U']] = 1;

        }


        /** 4. Прогоны по аналитике
         */
        $losses = []; // Потери

        $result = ['SEO' => [], 'ADV' => [], 'CRO' => [], 'OTH' => [], 'SITE' => [], 'DBG' => []];


        foreach ( $R as $C => $companyData)
        {
            $company = Company::model()->findByPk($C);
            $globMPP = $company->FK_manager;

            foreach ( $companyData as $M => $saleData)
            {

                if (!isset($result['SEO'][$M])) {$long['SEO'][$M] = 0;}
                if (!isset($result['CRO'][$M])) {$long['CRO'][$M] = 0;}
                if (!isset($result['ADV'][$M])) {$long['ADV'][$M] = 0;}
                if (!isset($result['OTH'][$M])) {$long['OTH'][$M] = 0;}
                if (!isset($result['SITE'][$M])) {$long['SITE'][$M] = 0;}
                if (!isset($result['DBG'][$M])) {$long['DBG'][$M] = 0;}


                $monthVal = [];
                $monthValbyMPP = [];
                foreach($mpp_list as $mpp) {
                    $monthVal[$mpp] = 0;
                    $monthValbyMPP[$mpp] = 0;
                }
                $mpp = 0;

                $firstMonthVD = 0;
                // newblock
                foreach ( $saleData as $criteria => $dataItem )
                {
                    // замешан МПП
                    $MPPused = false;
                    foreach ($dataItem['mgrs'] as $mgr=>$v)
                    {
                        if( in_array($mgr, $mpp_list)) {
                            $MPPused = true;
                            $mpp = $mgr;
                            break;
                        }
                    }

                    // варианты
                    // 1. Новая? Вот прям первая
                    if(
                        ($this->isFirstSale($criteria, $C, $M)) &&
                        $MPPused
                    )

                    {
                        if(
                            isset( $honeymoons[ $C ][ $M ]) &&
                            !isset( $honeymoons[ $C ][ $M-1 ])
                        )
                        {

                            if ($dataItem['EID'] != 226 && $dataItem['PRK'] == 13) {
                                $result['SEO'][$M] += $dataItem['VD'];
                            } elseif ($dataItem['PRK'] == 66) {
                                $result['CRO'][$M] += $dataItem['VD'];
                            } elseif ($dataItem['CTG'] == 1) {
                                $result['ADV'][$M] += $dataItem['VD'];
                            }
                            elseif (in_array($dataItem['PRK'], [10, 16, 67]) || $dataItem['EID'] == 185) {
                                $result['SITE'][$M] += $dataItem['VD'];
                            } else {
                                $result['OTH'][$M] += $dataItem['VD'];
                            }

                            //Считаем сумму ВД новых продаж за текущий месяц по компании
                            $firstMonthVD += $dataItem['VD'];

                            //иначе добавляем + к месячному приросту
                        } else/*if (isset( $honeymoons[ $C ][ $M ]))*/ {
                            $monthVal[$mpp] += $dataItem['VD'];
                            $monthValbyMPP[$mpp] += $dataItem['VD'];
                        }



                    }
                    else
                    {
                        // точно - без новых!
                        if ($MPPused) {
                            $monthValbyMPP[$mpp] += $dataItem['VD'];
                            $monthVal[$mpp] += $dataItem['VD'];
                        } else {
                            if (!isset($monthVal[$globMPP])) $monthVal[$globMPP] = 0;

                            $monthVal[$globMPP] += $dataItem['VD'];
                        }
                    }

                }
                // end of newblock


                //Если есть ВД первого месяца, считаем потери за месяц+ и месяц++ относительно первого
                if ($firstMonthVD > 0) {
                    //Получаем ВД за следующий месяц после первого
                    $nextMonthVD = 0;
                    if (isset($companyData[$M + 1])) {
                        foreach ($companyData[$M + 1] as $criteria => $data) {
                            $nextMonthVD += $data['VD'];
                        }
                    }

                    //Получаем ВД за месяц+2 после первого
                    $nextMonth2VD = 0;
                    if (isset($companyData[$M + 2])) {
                        foreach ($companyData[$M + 2] as $criteria => $data) {
                            $nextMonth2VD += $data['VD'];
                        }
                    }


                    if ($firstMonthVD > $nextMonthVD) {
                        if( !isset($losses[$M+1])) $losses[$M+1] = 0;
                        $losses[$M + 1] += $nextMonthVD - $firstMonthVD;

                    }
                    //Только при условии, что не попали в потерю первого месяца
                    elseif ($firstMonthVD > $nextMonth2VD) {
                        if( !isset($losses[$M+2])) $losses[$M+2] = 0;
                        $losses[$M+2] += $nextMonth2VD - $firstMonthVD;
                    }
                }



                // $monthVal  - объем за месяц
                // $monthValbyMPP - объем за месяц с помощью МПП
                // проверим, есть ли объемный прирост
                $best = 0;

                for ( $shift=1; $shift < 12; $shift ++)
                {
                    if( isset($monthlyTops[ $C][ $M - $shift] )
                        && ($monthlyTops[ $C][ $M - $shift] > $best)
                    )
                        $best = $monthlyTops[ $C][ $M - $shift ];

                }

                foreach ($monthVal as $mpp => $mVal) {
                    if (!in_array($mpp, $mpp_list)) {
                        if (!in_array($globMPP, $mpp_list)) continue;
                        $mpp = $globMPP;
                    }

                    //Для корректного расчета суммы допродажи после первой контекстной продажи
                    $addContext = 0;
                    if (isset($firstContext[$C][$M-1]) && !isset($secondContext[$C][$M]))
                        $addContext = $firstContext[$C][$M-1];
                    elseif (isset($firstContext[$C][$M-1]) && isset($secondContext[$C][$M]) && $secondContext[$C][$M] < $firstContext[$C][$M])
                        $addContext += $firstContext[$C][$M-1] - $secondContext[$C][$M];

                    $mVal += $addContext;

                    if( $mVal > $best ) //есть рост!!
                    {
                        // 1. Это льготный период?
                        if( isset( $honeymoons[ $C ][ $M])) {

                            if ($dataItem['EID'] != 226 && $dataItem['PRK'] == 13) {
                                $result['SEO'][$M] += $mVal - $best;
                            } elseif ($dataItem['PRK'] == 66) {
                                $result['CRO'][$M] += $mVal - $best;
                            } elseif ($dataItem['CTG'] == 1) {
                                $result['ADV'][$M] += $mVal - $best;
                            }
                            elseif (in_array($dataItem['PRK'], [10, 16, 67]) || $dataItem['EID'] == 185) {
                                $result['SITE'][$M] += $mVal - $best;
                            } else {
                                $result['OTH'][$M] += $mVal - $best;
                            }

                        } else {

                            if ($dataItem['EID'] != 226 && $dataItem['PRK'] == 13) {
                                $result['SEO'][$M] += min($mVal - $best, $monthValbyMPP[$mpp]);
                            } elseif ($dataItem['PRK'] == 66) {
                                $result['CRO'][$M] += min($mVal - $best, $monthValbyMPP[$mpp]);
                            } elseif ($dataItem['CTG'] == 1) {
                                $result['ADV'][$M] += min($mVal - $best, $monthValbyMPP[$mpp]);
                            }
                            elseif (in_array($dataItem['PRK'], [10, 16, 67]) || $dataItem['EID'] == 185) {
                                $result['SITE'][$M] += min($mVal - $best, $monthValbyMPP[$mpp]);
                            } else {
                                $result['OTH'][$M] += min($mVal - $best, $monthValbyMPP[$mpp]);
                            }

                        }
                    }
                }
            }
        }


        //Вычтем отмеченные НЕПОТЕРИ
        $noLosses = SalesLosses::model()->findAll('type = "total"');
        foreach ($noLosses as $nl) {
            if (isset($losses[$nl->month])) {
                $losses[$nl->month] -= $nl->sum;
            }
        }

        //Удалим лишние месяцы из массива
        $R = [];
        foreach ($result as $type => $data) {
            foreach ($data as $m => $val) {
                if ($m >= $this->mStart) {
                    $R[$type][$m] = $val;
                }
            }
        }

        foreach ($losses as $m => $data) {
            if ($m <= $this->mEnd) $R['LOS'][$m] = $data;
        }

        return $R;

    }


    public function getMonthlyStructure_OPShortRop($value, $strict = true)
    {
        $DS = $this->getBaseStructureQuery();
        $DS = $this->filterManagersMPP($DS);

        if ($strict) {
            $this->strict($DS);
        } else {
            $DS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');
        }

        $M = $this->getMonths();

        $DS->andWhere('(E.is_long = 0 or P.is_unique = 1)');
        $DS->select(
            $this->getField($value) . ' AS S,
            S.FK_control_month AS M, 
            S.FK_manager as Mgr, 
            S.FK_company as cmp, 
            S.ID as sid,
            E.is_long as is_long,
            E.ID as EID,
            P.FK_sale_product_kind as PRK,
            P.is_unique as is_unique')
            ->order('M, Mgr, cmp');

        $short = ['SITE' => [], 'OTH' => [], 'DOM' => [], 'HOST' => [], 'CRO' => []];

        $rows = $DS->queryAll();

        foreach ($rows as $line) {


            if (in_array($line['PRK'], [10, 16, 67]) || $line['EID'] == 185) {
                $short['SITE'][$line['M']] += $line['S'];

                $shortDBG['SITE'][$M[$line['M']]][] = $this->getDBGAr($line);
            }
            elseif ($line['PRK'] == 17) {
                $short['DOM'][$line['M']] += $line['S'];

                $shortDBG['DOM'][$M[$line['M']]][] = $this->getDBGAr($line);
            } elseif ($line['PRK'] == 19) {
                $short['HOST'][$line['M']] += $line['S'];

                $shortDBG['HOST'][$M[$line['M']]][] = $this->getDBGAr($line);
            } elseif ($line['PRK'] == 66) {
                $short['CRO'][$line['M']] += $line['S'];

                $shortDBG['CRO'][$M[$line['M']]][] = $this->getDBGAr($line);
            }
            else {
                $short['OTH'][$line['M']] += $line['S'];

                $shortDBG['OTH'][$M[$line['M']]][] = $this->getDBGAr($line);
            }

        }

        return [
            0 => $short,
            1 => $shortDBG,
        ];

    }
}