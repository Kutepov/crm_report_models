<?php
class SalesByCompaniesReportForm extends CFormModel
implements InterfaceReportForm {

    public $mpp_list;
    public $companies;
    public $firstContext = [];
    public $secondContext = [];

    private $_performed = false;
    private $mStart;
    private $mEnd;
    private $mpp;
    private $monthlyTops = [];
    private $saleBeforeP = [];
    private $saleIntoP = [];

    public function attributeLabels()
    {
        return [
            'mpp_list' => 'МПП'
        ];
    }

    private function getBaseStructureQuery($start = 0, $end = 0)
    {
        if ($start == 0) {
            $start = $this->mStart;
        }
        if ($end == 0) {
            $end = $this->mEnd;
        }

        $DS = Yii::app()->db->createCommand();
        $DS->from('{{sales_products}} P')
            ->join('{{sales}} S', 'P.FK_sale = S.ID')
            ->join('{{sales_products_kinds}} SK', 'P.FK_sale_product_kind = SK.ID')
            ->join('{{companies}} C', 'S.FK_company = C.ID')
            ->join('{{managers}} Prf', 'P.FK_performer = Prf.ID')
            ->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
            ->leftJoin('{{sales_summaries}} SMM', 'SMM.FK_sale = S.ID');

        $DS->where('S.FK_control_month >= ' . $start)
            ->andWhere('S.FK_control_month <= ' . $end)
            ->andWhere('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('C.active > 0')
            ->andWhere('S.FK_sale_state != 3');

        return $DS;
    }

    public function checkAccess ($action) {
        return Yii::app()->accDispatcher->haveIGotAccess([get_class($this) => "R"]);
    }

    public function setDefaults() {
        $this->mpp_list = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_SALEMGR);
        $this->mEnd = Month::getQueryID(date('m'), date('Y'));
        $this->mStart = $this->mEnd - 11;
        $this->fillFirstTimesTable();
    }

    public function prepareParams(&$arrData) {
        $this->mpp = $arrData['mpp_list'];
    }

    public function performReport() {
        $this->_performed = true;
    }

    public function setAttributes($values,$safeOnly=true, $field = 'VD') {
        $DS = Yii::app()->db->createCommand();
        $DS->selectDistinct('C.ID, C.name')
            ->from('{{companies}} C')
            ->join('{{sales}} S', 'S.FK_company = C.ID')
            ->join('{{sales_products}} P', 'S.ID = P.FK_sale')
            ->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
            ->where('S.FK_control_month >= ' . $this->mStart)
            ->andWhere('S.FK_control_month <= ' . $this->mEnd)
            ->andWhere('E.is_long = 1 and P.is_unique = 0')
            ->andWhere('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('C.active > 0')
            ->andWhere('S.FK_sale_state != 3')
            ->andWhere('C.FK_manager = '.$this->mpp)
            ->andWhere($this->getField($field).'> 0');
        $this->companies = $DS->queryAll();

        $field = $this->getField($field);
        /** 1. Максимумы помесячно! */
        $monthlyDS = $this->getBaseStructureQuery();
        $monthlyDS->andWhere('P.FK_sale_prod_elaboration NOT IN (15, 25, 31, 63, 75, 143, 153, 196, 229, 217, 222)'); //без балансов!
        $monthlyDS = $this->filterMarkedShorts($monthlyDS, false);
        $monthlyDS->select('S.FK_control_month AS M,
            S.FK_manager as U,
    		S.FK_company AS C, sum(' . $field . ') V')
            ->group('M, C');


        $rows = $monthlyDS->queryAll();
        foreach ($rows as $line)
        {
            if(!isset($this->monthlyTops[$line['C']])) $this->monthlyTops[$line['C']] = [];
            $this->monthlyTops[$line['C']][ $line['M']] = $line['V'];
        }

        $context = $this->addContextTops($this->monthlyTops, $field);
        $this->firstContext = $context[0];
        $this->secondContext = $context[1];


    }

    public function getSaleMonths($idCompany) {
        $DS = Yii::app()->db->createCommand();
        $DS->selectDistinct('FK_control_month')
            ->from('{{sales}} S')
            ->join('{{sales_products}} SP', 'S.ID = SP.FK_sale')
            ->join('{{companies}} C', 'S.FK_company = C.ID')
            ->join('{{spk_elaborations}} E', 'SP.FK_sale_prod_elaboration = E.ID')
            ->where('C.FK_manager = '.$this->mpp)
            ->andWhere('E.is_long = 1 and SP.is_unique = 0')
            ->andWhere('SP.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('C.active > 0')
            ->andWhere('S.FK_sale_state != 3')
            ->andwhere('S.FK_control_month >= ' . $this->mStart)
            ->andWhere('S.FK_control_month <= ' . $this->mEnd)
            ->andWhere('C.ID = '.$idCompany);

        $result = $DS->queryColumn();
        return $result;
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

    private function filterTrueShorts( &$DS, $positive = true)
    {
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

    private function addContextTops( & $monthlyTops, $field)
    {
        $DS = $this->getBaseStructureQuery($this->mStart, $this->mEnd);
        $DS = $this->filterMarkedShorts($DS, false);
        $DS->select('S.FK_control_month as M,
            S.FK_company as C,
            '.$field.' as V, 
            P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
            P.ID as PID');
        $rows2 = $DS->queryAll();

        //Для непотерь первого месяца по контекстным продажам
        //Берем первые продажи за год
        $DSin = $this->getBaseStructureQuery( $this->mStart, $this->mEnd);
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

    private function isFirstSale( $criteria, $company, $month )
    {
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

    private function fillFirstTimesTable(  )
    {
        $DS = $this->getBaseStructureQuery(  $this->mStart, $this->mEnd - 1 );
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

    public function getBestMonth($idCompany) {
        $C = $this->monthlyTops[$idCompany];

        $best = ['M' => 0, 'V' => 0];
        foreach ($C as $M => $V) {
            if ($V > $best['V']) {
                $best['M'] = $M;
                $best['V'] = $V;
            }
        }

        $result = Month::getMonthName($best['M']).' - '. number_format($best['V'], 2, ".", " ");

        return $result;
    }

    public function getSalesMonth($monthId, $companyId, $field = 'VD') {
        $value = $this->getField($field);
        $DS = $this->getBaseStructureQuery($monthId, $monthId);
        $DS->select('S.FK_control_month M, S.ID ID ,'.$value.' VD, E.name PNAME, SMM.payed as PAY');
        $DS->andWhere('C.ID = '.$companyId);
        $DS->andWhere('C.FK_manager = '.$this->mpp);
        $DS->andWhere($value.' > 0');
        $DS = $this->filterMarkedShorts($DS,false);
        $result = $DS->queryAll();
        return $result;
    }

    public function getGrowth($companyId, $monthId, $value) {
        if (isset($this->monthlyTops[$companyId])) {
            $top = 0;
            $growth = false;
            for ($i = $this->mStart;  $i < $monthId;  $i++) {
                if (isset($this->monthlyTops[$companyId][$i]) && $this->monthlyTops[$companyId][$i] > $top) {
                    $growth = true;
                    $top = $this->monthlyTops[$companyId][$i];
                }
            }

            if ($value - $top > 0 && $growth)
                return $value - $top;

            return false;
        }
        return false;
    }

    public function getMppName() {
        $manager = User::model()->findByPk($this->mpp);
        return $manager->fio;
    }

    public function isPerformed() { return $this->_performed; }
    public function defaultReport() {}

}
