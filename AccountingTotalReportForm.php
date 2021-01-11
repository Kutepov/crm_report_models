<?php
class AccountingTotalReportForm extends CFormModel
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

    public $monthlyTops = [];
    public $firstContext = [];
    public $secondContext = [];
    public $honeymoons = [];

    /**
     * Declares the validation rules.
     */
    public function rules()
    {
        return array(
            array('month, managers', 'safe'),
        );
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        return array();
    }

    /**
     * @return array
     */
    public function getMonths()
    {
        return Month::getMonthNameList($this->mStart, $this->mEnd, 1);
    }

    /**
     * @param bool $strict
     * @return array
     */
    public function newLongSales($strict = true)
    {
        return [];
    }

    /**
     * @param $action
     * @return bool
     */
    public function checkAccess($action)
    {
        return Yii::app()->accDispatcher->haveIGotAccess([get_class($this) => "R"]);
    }

    /**
     *
     */
    public function defaultReport()
    {
        $this->performReport();
    }

    /**
     * @param $arrData
     */
    public function prepareParams(&$arrData)
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
    private function fillFirstTimesTable()
    {
        $DS = $this->getBaseStructureQuery($this->mStart - 16, $this->mStart - 2);
        $DS->andWhere('E.is_long = 1')->group('G, C');


        $DSin = $this->getBaseStructureQuery();
        $DSin->andWhere('E.is_long = 1')->group('G, C');

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

        foreach ($data as $line) {
            if (!isset($R[$line['G']])) {
                $R[$line['G']] = [];
            }
            if (!isset($R[$line['G']][$line['C']])) {
                $R[$line['G']][$line['C']] = $line['M'];
            }
        }
        $this->saleBeforeP = $R;


        $R = [];
        $data2 = $DSin->queryAll();
        foreach ($data2 as $line) {
            if (!isset($R[$line['G']])) {
                $R[$line['G']] = [];
            }
            if (!isset($R[$line['G']][$line['C']])) {
                $R[$line['G']][$line['C']] = $line['M'];
            }

        }
        $this->saleIntoP = $R;


    }


    /**
     * @param SaleProduct $P
     * @return bool
     */
    public function isFirstSaleProduct(SaleProduct $P)
    {
        $criteria = $P->FK_sale_product_kind * 1000 + $P->elaboration->FK_feature1;
        $company = $P->sale->FK_company;
        $month = $P->sale->FK_control_month;

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
            ($month - $this->saleBeforeP[$criteria][$company]) > 11
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param $criteria
     * @param $company
     * @param $month
     * @return bool
     */
    public function isFirstSale($criteria, $company, $month)
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
            ($month - $this->saleBeforeP[$criteria][$company]) > 11
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
        $DS->selectDistinct('S.ID')
            ->andWhere('S.FK_control_month >= ' . ($this->mEnd - 1))
            ->andWhere(' ((S.W_lock = "Y" ) OR
        		( S.FK_control_month = ' . $this->mEnd . ' AND SMM.payed >= SMM.total * 0.1))');

        $this->whiteList = $DS->queryColumn();
    }

    /**
     * @return CDbCriteria
     */
    private function periodSalesCriteria()
    {

        $criteria = new CDbCriteria();
        $criteria->with = ['sale', 'elaboration'];
        $criteria->addCondition('sale.FK_sale_state != 3');
        $criteria->addCondition('sale.active = 1');
        $criteria->addCondition('t.active = 1');
        $criteria->addCondition('sale.FK_control_month >= ' . $this->mStart);
        $criteria->addCondition('sale.FK_control_month <= ' . $this->mEnd);

        $criteria->join = 'LEFT JOIN `t_sales_summaries` `smms` ON `smms`.FK_sale = `t`.ID';

        $criteria->addCondition('(( sale.W_lock = "Y" ) OR ( sale.FK_control_month >= ' . ($this->mEnd - 1) . '))');
        $criteria->distinct = true;

        return $criteria;
    }

    /**
     * @param string $F
     * @return array
     * @throws CException
     */
    public function getMonthlyNewSalesMD($F = 'MD')
    {
        $criteriaBeforeP = [];
        $criteriaIntoP = [];

        //Заполним критерий новизны клиента
        $DS = $this->getBaseStructureQuery($this->mStart - 16, $this->mStart - 1);
        $DS->select('S.FK_company as C, max( S.FK_control_month) as M')->group('C');
        $rows = $DS->queryAll();
        foreach ($rows as $line) {
            $criteriaBeforeP[$line['C']] = $line['M'];
        }
        //Заполним критерий новизны клиента2
        $DS = $this->getBaseStructureQuery();
        $DS->select('S.FK_company as C, min( S.FK_control_month) as M')->group('C');
        $rows = $DS->queryAll();
        foreach ($rows as $line) {
            $criteriaIntoP[$line['C']] = $line['M'];
        }


        $criteria = $this->periodSalesCriteria();
        $criteria->addCondition('elaboration.is_long = 1');
        $dataProvider = new CActiveDataProvider('SaleProduct', array(
            'criteria' => $criteria,
        ));
        $iterator = new CDataProviderIterator($dataProvider, 500);

        $result = [[], [], [], []];

        foreach ($iterator as $product) {
            $crit = $product->FK_sale_product_kind * 1000 + $product->elaboration->FK_feature1;
            $company = $product->sale->FK_company;
            $M = $product->sale->FK_control_month;

            // не первый
            if (!$this->isFirstSaleProduct($product)) {
                continue;
            }

            // строгий список
            if (($M < $this->mEnd - 1) || in_array($product->FK_sale, $this->whiteList)) {
                if ( // новый клиент
                    !($M > $criteriaIntoP[$company]) ||
                    (
                        isset($criteriaBeforeP[$company]) &&
                        ($M - $criteriaBeforeP[$company] > 15)
                    )
                ) {
                    if (!isset($result[0][$M])) {
                        $result[0][$M] = 0;
                    }
                    {
                        if ($F == 'MD') {
                            $result[0][$M] += $product->calcMD(true);
                        } else {
                            $result[0][$M] += $product->calcVD();
                        }
                    }
                } else // старый клиент
                {
                    if (!isset($result[1][$M])) {
                        $result[1][$M] = 0;
                    }
                    {
                        if ($F == 'MD') {
                            $result[1][$M] += $product->calcMD(true);
                        } else {
                            $result[1][$M] += $product->calcVD();
                        }
                    }

                }

            }

            // расширенный список
            if ($M >= $this->mEnd - 1 && !empty($criteriaIntoP[$company])) {
                if ( // новый клиент
                    $M > $criteriaIntoP[$company] ||
                    (
                        isset($criteriaBeforeP[$company]) &&
                        ($M - $criteriaBeforeP[$company] > 15)
                    )
                ) {
                    if (!isset($result[2][$M])) {
                        $result[2][$M] = 0;
                    }
                    {
                        if ($F == 'MD') {
                            $result[2][$M] += $product->calcMD(true);
                        } else {
                            $result[2][$M] += $product->calcVD();
                        }

                    }
                } else // старый клиент
                {
                    if (!isset($result[3][$M])) {
                        $result[3][$M] = 0;
                    }
                    {
                        if ($F == 'MD') {
                            $result[3][$M] += $product->calcMD(true);
                        } else {
                            $result[3][$M] += $product->calcVD();
                        }

                    }

                }


            }

        }

        return $result;
    }

    /**
     * @return array
     */
    public function getMonthlyNewSalesMidi()
    {

        $criteria = $this->periodSalesCriteria();
        $criteria->addCondition('elaboration.is_long = 1');
        $dataProvider = new CActiveDataProvider('SaleProduct', array(
            'criteria' => $criteria,
        ));
        $iterator = new CDataProviderIterator($dataProvider, 500);

        $result = [[], [], [], []];

        foreach ($iterator as $product) {
            $crit = $product->FK_sale_product_kind * 1000 + $product->elaboration->FK_feature1;
            $company = $product->sale->FK_company;
            $M = $product->sale->FK_control_month;

            // не первый
            if (!$this->isFirstSaleProduct($product)) {
                continue;
            }

            // строгий список
            if (($M < $this->mEnd - 1) || in_array($product->FK_sale, $this->whiteList)) {

                if (!isset($result[0][$M][$crit][$company])) {
                    $result[0][$M][$crit][$company] = 0;
                }
                $result[0][$M][$crit][$company] += $product->calcMD(true);


                if (!isset($result[1][$M][$crit][$company])) {
                    $result[1][$M][$crit][$company] = 0;
                }
                $result[1][$M][$crit][$company] += $product->calcVD();


            }

            // расширенный список
            if ($M >= $this->mEnd - 1) {
                if (!isset($result[2][$M][$crit][$company])) {
                    $result[2][$M][$crit][$company] = 0;
                }
                $result[2][$M][$crit][$company] += $product->calcMD(true);


                if (!isset($result[3][$M][$crit][$company])) {
                    $result[3][$M][$crit][$company] = 0;
                }
                $result[3][$M][$crit][$company] += $product->calcVD();
            }

        }

        $_res = [[], [], [], []];
        foreach ($result as $key => $midiblock) {
            foreach ($midiblock as $M => $data) {
                $S = 0;
                $N = 0;

                foreach ($data as $crit => $critData) {
                    foreach ($critData as $company => $value) {
                        $S += $value;
                        $N++;
                    }
                }

                $_res[$key][$M] = ($N) ? ($S / $N) : 0;
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
            'criteria' => $criteria,
        ));
        $iterator = new CDataProviderIterator($dataProvider, 500);

        foreach ($iterator as $product) {
            $crit = $product->FK_sale_product_kind * 1000 + $product->elaboration->FK_feature1;
            $company = $product->sale->FK_company;

            // не первый
            if (!$this->isFirstSaleProduct($product)) {
                continue;
            }

            $M = $product->sale->FK_control_month;
            if ($M >= $this->mEnd - 1) // за последние 2 месяца!
            {
                // 1. расширенный список
                $RX[$M][$crit][$company] = 1;

                // 2. белый список
                if (in_array($product->FK_sale, $this->whiteList)) {
                    $R[$M][$crit][$company] = 1;
                }
            } else {
                $R[$M][$crit][$company] = 1;
            }
        }

        $return = [];
        foreach ($R as $M => $RMdata) {
            $T = 0;
            foreach ($RMdata as $RMC => $RMarr) {
                $T += count($RMarr);
            }

            $return [$M] = $T;
        }

        $return_x = [];
        foreach ($RX as $M => $RMdata) {
            $T = 0;
            foreach ($RMdata as $RMC => $RMarr) {
                $T += count($RMarr);
            }

            $return_x [$M] = $T;
        }

        return [
            $return,
            $return_x
        ];
    }


    /**
     * @param $weeks
     * @return array
     */
    private function _getWeeklyPeriods($weeks)
    {
        $mond = new DateTime();
        $mond->modify('monday this week');
        $T = $mond->getTimestamp();
        $periods = [];

        for ($i = $weeks; $i >= 0; $i--) {
            $periods[] = $T - $i * 7 * 24 * 3600;
        }

        return $periods;
    }

    /**
     * @param $months
     * @return array
     */
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

    /**
     * @param $DS
     * @return mixed
     */
    private function strict(&$DS)
    {
        $DS->andWhere('((S.W_lock = "Y" ) OR ( S.FK_control_month <= ' . $this->mEnd . ' AND SMM.payed > 0))');
        return $DS;
    }

    private function lowStrict(&$DS)
    {
        $DS->andWhere('(
    			( S.W_lock = "Y" ) OR
        		( S.FK_control_month = ' . $this->mStart . ' AND SMM.payed > 0))');
        return $DS;
    }

    /**
     * @param $field
     * @return string
     */
    protected function getField($field)
    {
        if (strtolower($field) == 'md') {
            return '(1-(P.outlay2+P.discost)/100) * P.total/(1-P.discost/100)';
        }
        if (strtolower($field) == 'vd') {
            return '(1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)';
        } else {
            return 'P.total';
        }
    }

    /**
     * @param $DS
     * @param bool $positive
     * @return mixed
     */
    private function filterTrueShorts(&$DS, $positive = true)
    {
        $cond = 'P.FK_sale_product_kind IN (10, 17, 19) OR E.is_long = 0';
        if ($positive) {
            $DS->andWhere($cond);
        } else {
            $DS->andWhere(" NOT ($cond)");
        }
        return $DS;
    }

    /**
     * @param $DS
     * @param bool $positive
     * @return mixed
     */
    private function filterMarkedShorts(&$DS, $positive = true)
    {
        $DS = $this->filterTrueShorts($DS, false);
        $cond = 'P.is_unique>0';
        if ($positive) {
            $DS->andWhere($cond);
        } else {
            $DS->andWhere(" NOT ($cond)");
        }
        return $DS;
    }

    /**
     * @param int $start
     * @param int $end
     * @return CDbCommand
     */
    private function getBaseStructureQuery($start = 0, $end = 0)
    {
        if ($start == 0) {
            $start = $this->mStart;
        }
        if ($end == 0) {
            $end = $this->mEnd;
        }

        $DS2 = Yii::app()->db->createCommand();
        $DS2->select('ID')
            ->from('{{managers}} mgr')
            ->where('mgr.active = 1')
            ->andWhere('mgr.FK_department = 6');
        $rows = $DS2->queryColumn();

        $DS = Yii::app()->db->createCommand();
        $DS->from('{{sales_products}} P')
            ->join('{{sales}} S', 'P.FK_sale = S.ID')
            ->join('{{sales_products_kinds}} SK', 'P.FK_sale_product_kind = SK.ID')
            ->join('{{companies}} C', 'S.FK_company = C.ID')
            ->join('{{managers}} Prf', 'S.FK_acc_manager = Prf.ID')
            ->join('{{spk_elaborations}} E', 'P.FK_sale_prod_elaboration = E.ID')
            ->leftJoin('{{sales_summaries}} SMM', 'SMM.FK_sale = S.ID');

        $DS->where('S.FK_control_month >= ' . $start)
            ->andWhere('S.FK_control_month <= ' . $end)
            ->andWhere('P.active > 0')
            ->andWhere('S.active > 0')
            ->andWhere('C.active > 0')
            ->andWhere('S.FK_sale_state != 3')
            ->andWhere('P.FK_performer NOT IN ('.implode(', ', $rows).')');

        $this->filterMarkedShorts($DS, false);

        return $DS;
    }

    /**
     * @param $DS CDbCommand
     * @return CDbCommand
     */
    private function filterAccounting($DS)
    {
        $mm = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_ACC_MGR);
        //Добавим Анастасию Носкову
        $mm[] = 390;
        //Убираем Иру из отчета
        if (($key = array_search(11, $mm)) !== false) {
            unset($mm[$key]);
        }
        return $DS->andWhere('Prf.ID IN (' . implode(', ', $mm) . ')');
    }

    /**
     * @throws Exception
     */
    public function setDefaults()
    {
        $this->month = 6;

        if (!is_array($this->managers) || count($this->managers) == 0) {
            $this->managers_backup = array_keys($this->getParameterList('acc'));
        }

        if (!empty($this->settedMonth)) {
            $this->mEnd = $this->settedMonth;
        } else {
            $this->mEnd = Month::getQueryID(date('m'), date('Y'));
        }
        $this->mStart = $this->mEnd - $this->month + 1;

        $start = new DateTime(Month::getInnerDate($this->mEnd, date('Y-m-d')));
        $end = new DateTime(Month::getInnerDate($this->mEnd, date('Y-m-d')));
        $interval = new DateInterval('P' . ($this->month - 1) . 'M');
        $interval->invert = 1;
        $end->add($interval);
        $this->monthStart = $end->format('m');
        $this->monthEnd = $start->format('m');
        $this->yearStart = $end->format('Y');
        $this->yearEnd = $start->format('Y');

        $endDate = new DateTime(Month::getInnerDate($this->mEnd, date('Y-m-d')));
        $interval = new DateInterval('P' . ($this->month - 1) . 'M');
        $interval->invert = 1;
        $startDate = new DateTime(Month::getInnerDate($this->mEnd, date('Y-m-d')));
        $startDate->add($interval);
        $startDate->setDate($startDate->format('Y'), $startDate->format('m'), 1);
        $endDate->setTime(23, 59, 59);

        $this->endDate = $endDate;
        $this->startDate = $startDate;


        //Получаем максимумы по компаниям
        $this->monthlyTops = [];
        /** 1. Максимумы помесячно!
         */
        $monthlyDS = $this->getBaseStructureQuery($this->mEnd - 12, $this->mEnd);
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
    		S.FK_company AS C, sum((1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)) V')
            ->group('M, C');


        $rows = $monthlyDS->queryAll();
        foreach ($rows as $line)
        {
            if(!isset($this->monthlyTops[$line['C']])) $this->monthlyTops[$line['C']] = [];
            $this->monthlyTops[$line['C']][ $line['M']] = $line['V'];
        }

        //////////////////////////////////////////////////////////////////////////////
        $context = $this->addContextTops($monthlyTops, '(1-(P.outlay_VZ+P.discost)/100) * P.total/(1-P.discost/100)');
        $this->firstContext = $context[0];
        $this->secondContext = $context[1];
        /////////////////////////////////////////////////////////////////////

        $this->honeymoons = [];
        /** 2. По максимумам - точки начала льготных периодов */
        foreach ($this->monthlyTops as $C => $mData) {
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
                $this->honeymoons[$C] = $buff;
            }
        }

        foreach ($this->honeymoons as $C => $mData) {
            $buff = [];
            foreach ($mData as $M => $V) {
                for ($i = 0; $i < 6; $i++) {
                    $buff[$M + $i] = 1;
                }
            }
            $this->honeymoons[$C] = $buff;
        }
    }

    /**
     * @param $p
     * @return array
     */
    public function getParameterList($p)
    {
        switch ($p) {

            case 'acc':
                $mmList = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_ACC_MGR);
                //Добавим Анастасию Носкову
                $mmList[] = 390;
                //Убираем Иру из отчета
                if (($key = array_search(11, $mmList)) !== false) {
                    unset($mmList[$key]);
                }
                $fios = [];
                foreach ($mmList as $mm) {
                    $fios[$mm] = User::getOneManagerFio($mm);
                }
                natsort($fios);
                return $fios;
                break;

            case 'months':
                return $this->monthList(true);
                break;

            default:
                return array();
        }
    }

    /**
     * @param bool $full
     * @return array
     */
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
     * @param $month
     */
    public function setMonth($month)
    {
        $this->month = $month;
    }

    /**
     * @return array
     */
    protected function prepareReport()
    {
        //1. Интервал (мин и макс) айди месяцев по заданным фильтрам
        $monthsLimits = Month::getQueryLimits($this->monthStart, $this->yearStart, $this->monthEnd, $this->yearEnd);
        $this->monthLimits = $monthsLimits;
        return $monthsLimits;
    }


    /**
     * @return array
     * @throws Exception
     */
    public function performReport()
    {
        $this->setDefaults();
        $this->fillFirstTimesTable(); // вспомсписки для проверки "первости"
        $this->fillSalesWhiteList();  // белый список продаж на 2 мес.


        $monthsLimits = $this->prepareReport();

        //переформатим массив
        $result = array(
            [
                'header' => 'Общие объемы',
                'items' => [
                    'account_sales' => 1,
                    'retention_clients' => 1,
                    'retention_clients_summs' => 1,
                ]

            ],
            [
                'header' => 'Показатели за месяц',
                'items' => [
                    'account_month_stats' => [ 'class'=>'col-md-4', 'data'=>1],
                    'account_premonth_stats' => [ 'class'=>'col-md-4', 'data'=>1],
                ]

            ],
            [
                'header' => 'Индивидуальные показатели',
                'items' => [
                    'account_sales_individual' => [ 'class'=>'col-md-12', 'data'=>1],
                ]

            ],
            [
                'header' => 'Разрез по чеку компании за месяц',
                'items' => [
                    'sales_individual_average' => [ 'class'=>'col-md-12', 'data'=>1],
                ]

            ]
        );

        $this->result = $result;
        return $result;
    }

    public function getLosses()
    {
        $DS = $this->getBaseStructureQuery($this->mStart - 1);

        $DS->selectDistinct("
    			    			
    			sum({$this->getField('vd')}) as S,
    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
				S.FK_company as company,
    			S.FK_control_month as M,
    			S.ID as sid,
    			S.FK_acc_manager as mgr
    			")
            ->group('M, criteria, company');

        //TODO: Только по проведенным или оплаченным?
        $DS->andWhere('((S.W_lock = "Y" ) OR ( S.FK_control_month <= ' . $this->mEnd . ' AND SMM.payed > 0))');

        $rows = $DS->queryAll();

        //Собираем нужную структуру массива
        $salesByAccManager = [];
        foreach ($rows as $row) {
            if (!isset($salesByAccManager[$row['mgr']][$row['company']][$row['M']])) {
                $salesByAccManager[$row['mgr']][$row['company']][$row['M']] = 0;
            }
            if ($row['S'] > 0) {
                $salesByAccManager[$row['mgr']][$row['company']][$row['M']] += $row['S'];
            }
        }

        //Ищем потери в разрезе менеджер => компания => ммесяц
        $losses = [];
        foreach ($salesByAccManager as $mgr => $companies) {
            foreach ($companies as $company => $months) {
                foreach ($months as $month => $sum) {
                    //Если нет ВД следующего месяца или ВД меньше, чем текущий, скорей всего потеря
                    if ((!isset($salesByAccManager[$mgr][$company][$month + 1]) || $salesByAccManager[$mgr][$company][$month + 1] < $sum) && ($month + 1) <= $this->mEnd) {
                        //Если компания была передана другому аккаунт-менеджеру, это НЕ потеря
                        $anotherAccMgr = false;
                        foreach ($salesByAccManager as $mgr2 => $companies) {
                            if (isset($salesByAccManager[$mgr2][$company][$month + 1]) && $mgr != $mgr2) {
                                $anotherAccMgr = true;
                            }
                        }

                        //Если провалились сюда, точно потеря
                        if (!$anotherAccMgr) {
                            $losses[$mgr][$company][$month + 1] = !isset($salesByAccManager[$mgr][$company][$month + 1]) ? $salesByAccManager[$mgr][$company][$month] * -1 : $salesByAccManager[$mgr][$company][$month + 1] - $salesByAccManager[$mgr][$company][$month];
                        }
                    }
                }
            }
        }

        //Считаем общие потери в разрезе месяцев
        $R = ['data' => [], 'dbg' => $losses];
        foreach ($losses as $mgr => $companies) {
            foreach ($companies as $company => $months) {
                foreach ($months as $month => $sum) {
                    if (!isset($R['data'][$month])) $R['data'][$month] = 0;
                    $R['data'][$month] += $sum;
                }
            }
        }

        return $R;
    }

    /**
     * @param bool $firsts
     * @param int|bool $mpl
     * @return array
     * @throws Exception
     */
    public function getMonthlySalesOfKindF($firsts = true, $acc = false, $cond = false)
    {
        $DS = $this->getBaseStructureQuery();

        $DS->selectDistinct("
    			    			
    			sum({$this->getField('vd')}) as S,
    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
				S.FK_company as company,
    			S.FK_control_month as M,
    			S.ID as sid
    			")
            ->group('M, criteria, company');

        if ($acc) {
            $DS->andWhere('S.FK_acc_manager = '.$acc);
        } else {
            $DS = $this->filterAccounting($DS);
        }

        if ($cond) {
            $DS->andWhere($cond);
        }

        $this->strict($DS);
        $rows = $DS->queryAll();

        $R = [];


        $monthlySumms = [];
        foreach ($rows as $line) {
            if (!isset($R['data'][$line['M']]))
                $R['data'][$line['M']] = 0;

            if ($firsts == $this->isFirstSale($line['criteria'], $line['company'], $line['M'])) {
                $R['data'][$line['M']] += $line['S'];

                if (!$firsts && $acc) {
                    if (!isset($monthlySumms[$line['company']][$line['M']]))
                        $monthlySumms[$line['company']][$line['M']] = 0;

                    $monthlySumms[$line['company']][$line['M']] += $line['S'];
                }

                if ($line['S'] > 0)
                    $R['dbg'][$line['M']][] = [
                        'company' => $line['company'],
                        'sid' => $line['sid'],
                        'summ' => $line['S'],
                    ];
            }
        }

        if (!empty($monthlySumms)) {
            $extens = [];

            foreach ($monthlySumms as $C => $monthes) {
                foreach ($monthes as $M => $summ) {

                    //Получаем максимальную сумму продажи в месяц за последние 12 месяцев
                    $best = 0;
                    for ($shift = 1; $shift < 12; $shift++) {
                        if (isset($this->monthlyTops[$C][$M - $shift])
                            && ($this->monthlyTops[$C][$M - $shift] > $best)
                        ) {
                            $best = $this->monthlyTops[$C][$M - $shift];
                        }
                    }

                    //Для корректного расчета суммы допродажи после первой контекстной продажи
                    $addContext = 0;
                    if (isset($this->firstContext[$C][$M - 1]) && !isset($this->secondContext[$C][$M]))
                        $addContext = $this->firstContext[$C][$M - 1];
                    elseif (isset($this->firstContext[$C][$M - 1]) && isset($this->secondContext[$C][$M]) && $this->secondContext[$C][$M] < $this->firstContext[$C][$M])
                        $addContext += $this->firstContext[$C][$M - 1] - $this->secondContext[$C][$M];

                    $summ += $addContext;

                    if ($summ > $best) //есть рост!!
                    {
                        if (!isset($extens['dbg'][$M][$C]) && !isset($extens['values'])) {
                            $extens['values'][$M] = 0;
                            $extens['dbg'][$M][$C] = 0;
                        }

                        $extens['dbg'][$M][$C] += $summ - $best;
                        $extens['values'][$M] += $summ - $best;

                    }
                }
            }
        }

        if (!empty($extens)) {
            $R['data']['extens'] = $extens['values'];
            $R['data']['DBGextens'] = $extens['dbg'];
        }

        return $R;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getMonthlySalesOfTriggerList()
    {
        $return = [];
        $ms = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_ACC_MGR);
        //Добавим Анастасию Носкову
        $ms[] = 390;
        //Убираем Иру из отчета
        if (($key = array_search(11, $ms)) !== false) {
            unset($ms[$key]);
        }
        if (!empty($this->managers)) {
            $ms = array_intersect($ms, $this->managers);
        }
        $return['mgr'] = $this->getParameterList('acc');

        $return['data'] = [];

        foreach ($ms as $m) {
            $return['data'][$m] = [
                'new' => $this->getMonthlySalesOfKindF(true, $m),
                'old' => $this->getMonthlySalesOfKindF(false, $m),
                'dx' => $this->getMonthlySalesOfKind(false, $m),
            ];
        }

        return $return;
    }


    /**
     * @param bool $strict
     * @param int|bool $mpl
     * @return array
     */
    public function getMonthlySalesOfKind($strict = true, $acc = false)
    {
        $DS = $this->getBaseStructureQuery();

        if ($acc) {
            $DS->andWhere('S.FK_acc_manager = '.$acc);
        } else {
            $DS = $this->filterAccounting($DS);
        }

        if ($strict)
            $this->strict($DS);
        else
            $DS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');

        $DEBUG = clone($DS);

        $DS->selectDistinct("
    			sum({$this->getField('vd')}) as S,
    			S.FK_control_month as M")
            ->group('M');

        $DEBUG->select("
    			{$this->getField('vd')} as S,
    			S.FK_control_month as M,
    			S.FK_acc_manager as perf, 
    			S.FK_company as cmp, 
    			S.ID as sid,
    			P.ID as pid    			
    		")
            ->order('M, S DESC, cmp');

        $R = [];
        $dbg = [];

        $rows = $DS->queryAll();
        foreach ($rows as $line) {
            if (!isset($R[$line['M']])) {
                $R[$line['M']] = $line['S'];
            }
        }

        $rows = $DEBUG->queryAll();
        foreach ($rows as $line) {
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
                    's' => $line['S'],
                ];
            }
        }

        return ['data' => $R, 'dbg' => $dbg];
    }


    /**
     * @param string $value
     * @param bool $strict
     * @return array
     * @throws CException
     */
    public function getMngMonthStructure($value, $strict = true, $first = false, $month = false)
    {
        $field = $this->getField($value);
        $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_ACC_MGR);
        //Добавим Анастасию Носкову
        $mpp_list[] = 390;
        if (($key = array_search(11, $mpp_list)) !== false) {
            unset($mpp_list[$key]);
        }
        $mpp_list = implode(', ', $mpp_list);

        if ($month) {
            $mStart = $this->mEnd - 1;
            $mEnd = $this->mEnd - 1;
        } else {
            $mStart = $this->mEnd;
            $mEnd = $this->mEnd;
        }


        $DS = $this->getBaseStructureQuery($mStart, $mStart);

        $DS->selectDistinct("		    			
    			sum({$this->getField('vd')}) as S,
    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
				S.FK_company as company,
    			S.FK_control_month as M,
				C.FK_acc_manager as Prf
    			")
            ->group('Prf, criteria, company');


        if ($strict) {
            $this->strict($DS);
        } else {
            $DS->andWhere('S.FK_control_month =  (' . $mStart . ')');
        }
        $DS = $this->filterAccounting($DS);
        $DS->andWhere('C.FK_acc_manager in ('.$mpp_list.')');

        $rows = $DS->queryAll();

        $R = [];

        $monthlySumms = [];
        foreach ($rows as $line) {
            if (!isset($R[$line['Prf']]))
                $R[$line['Prf']] = 0;

            if ($first == $this->isFirstSale($line['criteria'], $line['company'], $line['M'])) {
                $R[$line['Prf']] += $line['S'];

                if (!$first && $strict) {
                    if (!isset($monthlySumms[$line['Prf']][$line['company']][$line['M']]))
                        $monthlySumms[$line['Prf']][$line['company']][$line['M']] = 0;

                    $monthlySumms[$line['Prf']][$line['company']][$line['M']] += $line['S'];
                }
            }
        }

        if (!empty($monthlySumms)) {
            $extens = [];

            foreach ($monthlySumms as $manager => $companies) {
                foreach ($companies as $C => $monthes) {
                    foreach ($monthes as $M => $summ) {

                        //Получаем максимальную сумму продажи в месяц за последние 12 месяцев
                        $best = 0;
                        for ($shift = 1; $shift < 12; $shift++) {
                            if (isset($this->monthlyTops[$C][$M - $shift])
                                && ($this->monthlyTops[$C][$M - $shift] > $best)
                            ) {
                                $best = $this->monthlyTops[$C][$M - $shift];
                            }
                        }

                        //Для корректного расчета суммы допродажи после первой контекстной продажи
                        $addContext = 0;
                        if (isset($this->firstContext[$C][$M - 1]) && !isset($this->secondContext[$C][$M]))
                            $addContext = $this->firstContext[$C][$M - 1];
                        elseif (isset($this->firstContext[$C][$M - 1]) && isset($this->secondContext[$C][$M]) && $this->secondContext[$C][$M] < $this->firstContext[$C][$M])
                            $addContext += $this->firstContext[$C][$M - 1] - $this->secondContext[$C][$M];

                        $summ += $addContext;

                        if ($summ > $best) //есть рост!!
                        {
                            if (!isset($extens['dbg'][$manager][$M][$C]) && !isset($extens['values'][$manager])) {
                                $extens['values'][$manager] = 0;
                                $extens['dbg'][$manager][$M][$C] = 0;
                            }

                            $extens['dbg'][$manager][$M][$C] += $summ - $best;
                            $extens['values'][$manager] += $summ - $best;

                        }
                    }
                }
            }
        }

        if (!empty($extens))
            $R['extens'] = $extens;

        return $R;
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


    private function getAllLongSales($field, $acc) {
        $field = $this->getField($field);
        $DS = $this->getBaseStructureQuery($this->mStart, $this->mEnd);
        $DS = $this->filterMarkedShorts($DS, false);
        $DS->select('S.FK_control_month as M,
            S.FK_company as C,
            '.$field.' as V, 
            P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
            P.ID as PID,
            S.ID as SID,
            C.FK_acc_manager as mgr');
        $DS->andWhere('Prf.ID = '.$acc);

        $rows = $DS->queryAll();
        return $rows;
    }

    public function salesByCheck($field, $type, $acc)
    {
        $rows = $this->getAllLongSales($field, $acc);
        $summs = [];
        foreach ($rows as $line) {
            if (!isset($summs[$line['M']][$line['C']]['summ']))
                $summs[$line['M']][$line['C']]['summ'] = 0;

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

    public function averageCheckByCompanies($field, $acc) {
        $rows = $this->getAllLongSales($field, $acc);
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

    public function getAverageDataByManagers() {

        $return = [];
        $ms = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_ACC_MGR);
        //Добавим Анастасию Носкову
        $ms[] = 390;
        //Убираем Иру из отчета
        if (($key = array_search(11, $ms)) !== false) {
            unset($ms[$key]);
        }
        if (!empty($this->managers)) {
            $ms = array_intersect($ms, $this->managers);
        }

        foreach ($ms as $m) {
            $return[$m] = [
                'allVd' => $this->salesByCheck('vd', 'summ', $m),
                'numComp' => $this->salesByCheck('vd', 'companies', $m),
                'average' => $this->averageCheckByCompanies('vd', $m),
            ];
        }

        return $return;

    }


    public function retentionClientsData()
    {
        //Получение всех длинных оплаченных продуктов за последние 12 месяцев
        $DS = $this->getBaseStructureQuery($this->mStart - 6, $this->mEnd);
        $this->strict($DS);
        $DS->selectDistinct("{$this->getField('vd')} as S,S.FK_control_month as M, S.ID as sale, P.ID as P, C.ID as C")->order('C, M');
        $queryArray = $DS->queryAll();

        //Формирование массива, группированного по клиентам
        $arrayByCompanies = [];
        foreach ($queryArray as $item) {
            if (!isset($arrayByCompanies[$item['C']][$item['M']]) && $item['S'] > 0) {
                $arrayByCompanies[$item['C']][$item['M']] = 0;
            }
            if ($item['S'] > 0) {
                $arrayByCompanies[$item['C']][$item['M']] += $item['S'];
            }
        }

        $result = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $resultDBG = [];
        foreach ($arrayByCompanies as $C => $item) {
            switch (count($item)) {
                case 1:
                    $result[1]++;
                    $resultDBG[1][] = $C;
                    break;
                case 2:
                    $result[2]++;
                    $resultDBG[2][] = $C;
                    break;
                case 3:
                    $result[3]++;
                    $resultDBG[3][] = $C;
                    break;
                case 4:
                    $result[4]++;
                    $resultDBG[4][] = $C;
                    break;
                default:
                    $result[5]++;
                    $resultDBG[5][] = $C;
                    break;
            }
        }
        return [$result, $resultDBG];
    }


    public function retentionClientsSumData()
    {
        //Получение всех длинных оплаченных продуктов за последние 12 месяцев
        $DS = $this->getBaseStructureQuery($this->mStart - 6, $this->mEnd);
        $this->strict($DS);
        $DS->selectDistinct("{$this->getField('vd')} as S,S.FK_control_month as M, S.ID as sale, P.ID as P, C.ID as C")->order('C, M');
        $queryArray = $DS->queryAll();

        //Формирование массива, группированного по клиентам
        $arrayByCompanies = [];
        foreach ($queryArray as $item) {
            if (!isset($arrayByCompanies[$item['C']][$item['M']]) && $item['S'] > 0) {
                $arrayByCompanies[$item['C']][$item['M']] = 0;
            }
            if ($item['S'] > 0) {
                $arrayByCompanies[$item['C']][$item['M']] += $item['S'];
            }
        }

        $result = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $resultDBG = [];
        foreach ($arrayByCompanies as $C => $item) {
            $sum = 0;
            switch (count($item)) {
                case 1:
                    foreach ($item as $S) {
                        $sum += $S;
                    }
                    $result[1] += $sum;
                    $resultDBG[1][$C] = $sum;
                    break;
                case 2:
                    foreach ($item as $S) {
                        $sum += $S;
                    }
                    $result[2] += $sum;
                    $resultDBG[2][$C] = $sum;
                    break;
                case 3:
                    foreach ($item as $S) {
                        $sum += $S;
                    }
                    $result[3] += $sum;
                    $resultDBG[3][$C] = $sum;
                    break;
                case 4:
                    foreach ($item as $S) {
                        $sum += $S;
                    }
                    $result[4] += $sum;
                    $resultDBG[4][$C] = $sum;
                    break;
                default:
                    foreach ($item as $S) {
                         $sum += $S;
                    }
                    $result[5] += $sum;
                    $resultDBG[5][$C] = $sum;
                    break;
            }
        }
        return [$result, $resultDBG];
    }

}