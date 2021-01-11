<?php

class SaleFunnelReportForm extends CFormModel
	implements InterfaceReportForm
{
    
    public $dateStart;
    public $dateEnd;
    public $FK_manager_mode = 1;
    public $FK_lead_mode = 0;
    public $FK_deal_mode = 0;
    public $FK_compare_mode = 0;
    public $managers = [];
    
    
    private $comparationLists_LI = [];
    private $comparationLists_LD = [];
    
    
    private $ready = 0;
    
    public function rules()
    {
        return array(// name, email, subject and body are required
			[ 'dateStart, dateEnd, managers, FK_manager_mode, FK_lead_mode, FK_deal_mode, FK_compare_mode', 'safe'],
        );
    }
    
    public function getParameterList( $p )
    {
    	$R = [];
    	switch ($p)
    	{
    		case 'FK_manager_mode':
    			$R = [
    				1 => 'Все',
    				2 => 'Выбранные'
    			];
    			break;
    			
    		case 'FK_lead_mode':
    		case 'FK_deal_mode':
    			$R = [
    				0 => 'Все',
    				1 => 'Незавершенные',
    				2 => 'Завершенные',
    			];
    			break;
    			
    		case 'FK_compare_mode':
    			$R = [
    				0 => 'Сравн. по сотрудникам',
    				1 => 'Сравн. по источникам',
    			];
    			break;
    		case 'managers':
    			$ids = LeadItem::model()->columnValues('FK_manager');
    			$R = User::getDDList( 'short', 'ID in ('. implode(', ', $ids).')', 'fio');
    			break;
    			
    		case 'conversion_pts':
    			
    			$R = LAConversionPoint::getDDList();
    			
    			break;
    			
    	}
    	
    	return $R;
    }
    
    public function checkAccess( $actions )
    {
    	return true;
    }

    public function attributeLabels()
    {
        return array(
        );
    }
    
    public function prepareParams(&$arrData)
    {
    	
    	if( isset( $arrData[ 'dateStart']))
    		$arrData[ 'dateStart'] = date( "Y-m-d", strtotime( $arrData[ 'dateStart']));
    	if( isset( $arrData[ 'dateEnd']))
    		$arrData[ 'dateEnd'] = date( "Y-m-d", strtotime( $arrData[ 'dateEnd']));
    			
    			
    }

    public function setDefaults()
    {
    }
    
    protected function prepareReport()
    {	
    }
    
    public function defaultReport()
    {	
    }


    public function performReport()
    {
    	
    	
    	$this->buildComparationList1(  );
    	$this->buildComparationList2(  );
    	
    	
    	
    	
    	$this->ready = 1;        
    }
    
    

    
    public function ready()
    {
    	return $this->ready;
    
    }
	
    /**
     * Заголовки колонок.
     * Пользователи или источники
     * @return Ambigous|array
     */
    public function getColumnHeaders()
    {
    	if( count( $this->comparationLists_LI))
    	{
    		if( $this->FK_compare_mode == 0 )
    		{
    			$criteria = 'ID in (' . implode(', ', array_keys( $this->comparationLists_LI)) . ')';
    			return  User::getDDList('short', $criteria, 'fio');
    		}
    		else
    		{
    			$keys = array_keys( $this->comparationLists_LI);
    			$criteria = 'ID in (' . implode(', ', $keys) . ')';
    			$R = LAConversionPoint::getDDList('name', $criteria);
    			if( in_array(0, $keys)) $R[0] = 'Другое';
    			return  $R;
    		}
    	}
    	return [];
    	
    }
    
    
    public function getLeadNumbers( $keys )
    {
    	$R = [];
    	foreach( $keys as $k)
    	if( !isset( $this->comparationLists_LI[$k]))
    		$R[$k] = 0;
    	else
    		$R[$k] = $this->comparationLists_LI[$k]->count();
    	
    	return $R;
    }
    
    public function getLeadNumbers2( $keys )
    {
    	$R = [];
    	foreach( $keys as $k)
    		if( !isset( $this->comparationLists_LD[$k]))
    			$R[$k] = 0;
    		else
    			$R[$k] = count($this->comparationLists_LD[$k]);
    				
    	return $R;
    }
    
    
    
    public function getLeadNumber( $k)
    {
    	if( !isset( $this->comparationLists_LI[$k]))
    		return 0;
    	
    	return $this->comparationLists_LI[$k]->count();
    }
    
    public function getLeadsFailed( $k)
    {
    	if( !isset( $this->comparationLists_LI[$k]))
    		return 0;
    		
   		$DS = $this->getLeadDS();
   		$DS->andWhere('t.FK_fail_reason > 0');
   		$DS->andWhere('t.ID IN (' . implode(', ', $this->comparationLists_LI[$k]->toArray()).')');
   		$DS->select( "count(t.ID)");
    	
   		return $DS->queryScalar();
    }
    
    public function getLeadNumFailed( $keys)
    {
    	$R = [];
    	foreach ($keys as $k)
    	{
    		$R[$k] = $this->getLeadsFailed($k);
    	}
    	return $R;
    }
    
    
    public function getLeadsReachedStage( $categ, $FK_stage)
    {
    	$DS = $this->getLeadLogDS( $this->comparationLists_LI[$categ]->toArray());
    	$DS->selectDistinct( "count(t.ID)");
    	$DS->andWhere("FK_stage = $FK_stage");
    	
    	return $DS->queryScalar();
    }
    
    public function getLeadNumReachedStage( $keys, $FK_stage)
    {
    	$R = [];
    	foreach ($keys as $categ)
    	{
    		$DS = $this->getLeadLogDS( $this->comparationLists_LI[$categ]->toArray());
    	   	$DS->selectDistinct( "count(t.ID)");
    		$DS->andWhere("FK_stage = $FK_stage");
    		
    		$R[ $categ ] = $DS->queryScalar();
    	}
    	return $R; 

    }
    
    public function getLDNumReachedStage( $keys, $stage)
    {    
    	$R = [];
	    foreach ($keys as $categ)
	    {
	    	if( count($this->comparationLists_LD[$categ] ))
	    	{
		    	$DS = $this->getLDLogDS( $this->comparationLists_LD[$categ]);
		    	$DS->selectDistinct( "count(t.id)");
		    	$DS->andWhere("t.new_status = $stage");
		    	$R[ $categ ] = $DS->queryScalar();
	    	}
	    	else 
	    		$R[ $categ ] = 0;
	    }
	    return $R;
    }


    /**
     * Нужно получить расклад по Менеджер - Список его лидов
     * @param unknown $field
     */
    private function buildComparationList1(  )
    {
    	
    	$DS = $this->getLeadDS();
    	$R = [];
    	
    	if( $this->FK_compare_mode == 0 ) // по сотрудникам
    	{
    		$DS->selectDistinct( "t.ID, t.FK_manager as C");
    		$rows = $DS->queryAll();
    		foreach ($rows as $line)
    		{
    			if( !isset( $R[ $line['C']]))
    				$R[ $line['C']] = new CList();
    				$R[$line['C']]->add( $line['ID']);
    		}
    		
    	}
    	elseif( $this->FK_compare_mode == 1 ) // по источникам
    	{
    		$DS->leftJoin( '{{ldanl_analitic}} la', 'la.FK_lead = t.ID');
    		$DS->selectDistinct( "t.ID, t.FK_source as C1, la.FK_conv_point as C2");
    		$rows = $DS->queryAll();
    		foreach ($rows as $line)
    		{
    			$c = ( !empty($line['C2'])) ? $line['C2'] : $this->convertToA( $line['C1'] );
    			if( !isset( $R[ $c]))
    				$R[ $c ] = new CList();
    			$R[ $c ]->add( $line['ID']);
    		}
    		
    		
    	}
    	
    	   	
    	$this->comparationLists_LI = $R;
    	
    }
    
    
    protected function convertToA( $i )
    {
    	/**
    	$target 
    		1	=> 'Сайт iq-adv.ru',
    		2	=> 'Рассылка',
    		3	=> 'Рекомендация',
    		4	=> 'Бывший клиент',
    		5	=> 'Текущий клиент',
    		6	=> 'Тендер',
    		7	=> 'Исх. звонок МПП',
    		8	=> 'Мероприятие',
    		9	=> 'Телемаркетинг',
    	
    	
    	$source
	    	1 	Входящий звонок
	    	4 	Email
	    	7 	Пришел в офис
	    	
	    	2 	Заявка с сайта 
	    	3 	CallbackHunter  	
	    	5 	По рекомендации
	    	6 	Тендер 	
	    	8 	Исходящий звонок МПП
	    	9 	Телемаркетинг 	
	    	10 	Форум 
	    **/
    	
    	$return = [
    		1 => 0,
    		2 => 1,
    		3 => 1,
    		4 => 0,
    		5 => 3,
    		6 => 6,
    		7 => 0,
    		8 => 7,
    		9 => 9,
    		10 => 8
    	];
    	
    	return $return[$i];
    }

    
    /**
     * Нужно получить расклад по Список его лидов -> Список сделок!
     * @param unknown $field
     */
    private function buildComparationList2(  )
    {
    	foreach ( $this->comparationLists_LI as $key=>$list )
    	{
    		$R = [];
    		if( $list->count() )
    		{
    			$DS = $this->getLeadDS();
    			$DS->selectDistinct( "t.FK_lead");
    			$DS->andWhere( 't.ID IN (' . implode( ', ', $list->toArray()). ')');
    			$DS->join( '{{leads}} l','l.ID = t.FK_lead' );
    			
    			if( $mode = $this->FK_deal_mode)
    			{
    				$condition = '(l.status IN (4,5))';
    				//	1 => 'Незавершенные',
    				if( $mode == 1) $DS->andWhere( 'NOT '.$condition);
    				//	2 => 'Завершенные',
    				if( $mode == 2) $DS->andWhere( $condition);
    				
    			}
    			
    			$R = $DS->queryColumn();
    		}
    		
    		$this->comparationLists_LD[$key] = $R;
    	}
   	
    }
    
    
    
	
    private function getLeadDS()
    {
    	$DS = Yii::app()->db->createCommand();
    	$DS->from('{{lead_item}} t');    	
    	$DS->where( 'add_time >= "'.$this->dateStart . '"');
    	$DS->andWhere( 'add_time <= "'.$this->dateEnd . '"');
    	$DS->andWhere('t.active = 1');
    	$DS->andWhere('t.FK_fail_reason != '.LeadItemFailureReason::REASON__TEST_SPAM);
    	
    	if( $this->FK_manager_mode == 2 && count( $this->managers )) // отбор по ответственным
    		$DS->andWhere('t.FK_manager IN (' . implode(',',$this->managers) . ')');
    	
    	if( $mode = $this->FK_lead_mode)
    	{
    		$condition = '(t.FK_stage IN (4) OR t.FK_fail_reason > 0)';
    		//	1 => 'Незавершенные',
    		if( $mode == 1) $DS->andWhere( 'NOT '.$condition);
	    	//	2 => 'Завершенные',
    		if( $mode == 2) $DS->andWhere( $condition);
    		
    	}
    	return $DS;
    }
    
    private function getLeadLogDS( $list )
    {
    	$DS = Yii::app()->db->createCommand();
    	$DS->from('{{lead_item_stage_log}} t');
    	
    	$DS->where( 't.FK_lead_item IN (' . implode(', ', $list).')');
    	 	
    	
    	return $DS;
    }
    
    private function getLDLogDS( $list )
    {
    	$DS = Yii::app()->db->createCommand();
    	$DS->from('{{leads_activity}} t');
    	
    	$DS->where( 't.lead_id IN ( ' . implode(', ', $list).')');
    	$DS->andWhere( 't.type = 3');
    	    	
    	return $DS;
    }
    
   
}