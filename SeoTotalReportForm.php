<?php

/**
 * Class SeoTotalReportForm
 */
class SeoTotalReportForm extends CFormModel
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
        $DS = $this->getBaseStructureQuery($this->mStart - 16, $this->mStart - 1);
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
            ($month - $this->saleBeforeP[$criteria][$company]) > 12
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
     * Белый список клиентов на началоквартала
     * т.е. все у кого были "длинные" продажи в течение года до того
     * @param unknown $start
     */

    private function _getTrimesterClientOldies($start)
    {
        $DS = $this->getBaseStructureQuery($start - 13, $start - 1);
        $DS = $this->strict($DS);
        // оба условия разовости в минус
        $DS = $this->filterMarkedShorts($DS, false);

        $DS->selectDistinct('S.FK_company AS C');

        return $DS->queryColumn();
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

    /**
     * @param $DS CDbCommand
     * @return CDbCommand
     */
    private function filterSeo($DS)
    {
        $seo = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_SEO);
        if (!empty($this->managers)) {
            $seo = array_intersect($seo, $this->managers);
        }
        return $DS->andWhere('Prf.ID IN (' . implode(', ', $seo) . ')');
    }

    /**
     * @throws Exception
     */
    public function setDefaults()
    {
        $this->month = 6;

        if (!is_array($this->managers) || count($this->managers) == 0) {
            $this->managers_backup = array_keys($this->getParameterList('seo'));
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
    }

    /**
     * @param $p
     * @return array
     */
    public function getParameterList($p)
    {
        switch ($p) {

            case 'seo':
                $seoList = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_SEO);
                $fios = [];
                foreach ($seoList as $seo) {
                    $fios[$seo] = User::getOneManagerFio($seo);
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
                    'seo_sales' => ['class' => 'col-md-4', 'data' => 1],
                    'seo_stats_current_month' => ['class' => 'col-md-4', 'data' => 1],
                ]

            ],
            [
                'header' => 'Показатели за месяц',
                'items' => [
                    'seo_month_stats' => ['class' => 'col-md-4', 'data' => 1],
                    'seo_premonth_stats' => ['class' => 'col-md-4', 'data' => 1],
                ]

            ],
            [
                'header' => 'Индивидуальные показатели',
                'items' => [
                    'seo_sales_individual' => ['class' => 'col-md-12', 'data' => 1],
                ]

            ],
            [
                'header' => 'Разрез по чеку компании за месяц',
                'items' => [
                    'sales_individual_average' => ['class' => 'col-md-12', 'data' => 1],
                ]

            ]
        );

        $this->result = $result;
        return $result;
    }


    /**
     * @param bool $firsts
     * @param int|bool $mpl
     * @return array
     * @throws Exception
     */
    public function getMonthlySalesOfKindF($firsts = true, $mpl = false, $cond = false)
    {
        $DS = $this->getBaseStructureQuery();

        $DS->selectDistinct("
    			    			
    			sum({$this->getField('vd')}) as S,
    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
				S.FK_company as company,
    			S.FK_control_month as M,
    			S.ID as sid,
    			P.is_unique as is_unique,
    			E.is_long as is_long
    			")
            ->group('M, criteria, company');

        if ($mpl) {
            $DS->andWhere('Prf.ID = :mpl', [':mpl' => $mpl]);
        } else {
            $DS = $this->filterSeo($DS);
        }

        if ($cond) {
            $DS->andWhere($cond);
        }

        $this->strict($DS);
        $rows = $DS->queryAll();

        $R = [];


        foreach ($rows as $line) {
            if (!isset($R['data']['long'][$line['M']])) {
                $R['data']['long'][$line['M']] = 0;
            }

            if (!isset($R['data']['short'][$line['M']])) {
                $R['data']['short'][$line['M']] = 0;
            }

            if ($firsts == $this->isFirstSale($line['criteria'], $line['company'], $line['M'])) {
                if ($line['is_long'] == 1 && $line['is_unique'] == 0) {
                    $R['data']['long'][$line['M']] += $line['S'];
                } else {
                    $R['data']['short'][$line['M']] += $line['S'];
                }

                if ($line['S'] > 0) {
                    $R['dbg'][$line['M']][] = [
                        'company' => $line['company'],
                        'sid' => $line['sid'],
                        'summ' => $line['S'],
                    ];
                }
            }
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
        $ms = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_SEO);
        if (!empty($this->managers)) {
            $ms = array_intersect($ms, $this->managers);
        }
        $return['mgr'] = $this->getParameterList('seo');

        $return['data'] = [];

        foreach ($ms as $m) {
            $return['data'][$m] = [
                'new' => $this->getMonthlySalesOfKindF(true, $m, 'E.ID NOT IN (249)'),
                'old' => $this->getMonthlySalesOfKindF(false, $m, 'E.ID NOT IN (249)'),
                'short' => $this->getMonthlySalesOfKindF(true, $m, 'E.ID IN (249)'),
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
    public function getMonthlySalesOfKind($strict = true, $mng = false)
    {
        $DS = $this->getBaseStructureQuery();

        if ($mng) {
            $DS->andWhere('Prf.ID = :mng', [':mng' => $mng]);
        } else {
            $DS = $this->filterSeo($DS);
        }

        if ($strict) {
            $this->strict($DS);
        } else {
            $DS->andWhere('S.FK_control_month >=  (' . ($this->mEnd - 1) . ')');
        }

        $DEBUG = clone($DS);

        $DS->selectDistinct("
    			sum({$this->getField('vd')}) as S,
    			S.FK_control_month as M")
            ->group('M');

        $DEBUG->select("
    			{$this->getField('vd')} as S,
    			S.FK_control_month as M,
    			P.FK_performer as perf, 
    			S.FK_company as cmp, 
    			S.ID as sid,
    			P.ID as pid,
    			P.is_unique as is_unique,
    			E.is_long as is_long
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
                    'is_unique' => $line['is_unique'],
                    'is_long' => $line['is_long']
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
        $mpp_list = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_SEO);

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
				P.FK_performer as Prf
    			")
            ->group('Prf, criteria, company');


        if ($strict) {
            $this->strict($DS);
        } else {
            $DS->andWhere('S.FK_control_month =  (' . $mStart . ')');
        }
        $DS = $this->filterSeo($DS);

        $rows = $DS->queryAll();

        $R = [];


        foreach ($rows as $line) {
            if (!isset($R[$line['Prf']])) {
                $R[$line['Prf']] = 0;
            }

            if ($first == $this->isFirstSale($line['criteria'], $line['company'], $line['M'])) {
                $R[$line['Prf']] += $line['S'];
            }
        }

        return $R;
    }


    private function getAllLongSales($field, $acc)
    {
        $field = $this->getField($field);
        $DS = $this->getBaseStructureQuery($this->mStart, $this->mEnd);
        $DS = $this->filterMarkedShorts($DS, false);
        $DS->select('S.FK_control_month as M,
            S.FK_company as C,
            ' . $field . ' as V, 
            P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
            P.ID as PID,
            S.ID as SID,
            C.FK_acc_manager as mgr');
        $DS->andWhere('Prf.ID = ' . $acc);

        $rows = $DS->queryAll();
        return $rows;
    }

    public function salesByCheck($field, $type, $acc)
    {
        $rows = $this->getAllLongSales($field, $acc);
        $summs = [];
        foreach ($rows as $line) {
            if (!isset($summs[$line['M']][$line['C']]['summ'])) {
                $summs[$line['M']][$line['C']]['summ'] = 0;
            }

            $summs[$line['M']][$line['C']]['summ'] += $line['V'];
            if ($line['V'] > 0) {
                $summs[$line['M']][$line['C']]['dbg'][] = [
                    'V' => $line['V'],
                    'SID' => $line['SID'],
                    'C' => $line['C']
                ];
            }
        }

        $result = [];
        foreach ($summs as $M => $companies) {
            foreach ($companies as $C => $S) {
                if ($S['summ'] <= 25000) {
                    if (!isset($result[0]['data'][$M])) {
                        $result[0]['data'][$M] = 0;
                    }
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
                    } else {
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
                } elseif ($S['summ'] > 25000 && $S['summ'] <= 50000) {
                    if (!isset($result[1]['data'][$M])) {
                        $result[1]['data'][$M] = 0;
                    }
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
                    } else {
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
                } else {
                    if (!isset($result[2]['data'][$M])) {
                        $result[2]['data'][$M] = 0;
                    }
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
                    } else {
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

    public function averageCheckByCompanies($field, $acc)
    {
        $rows = $this->getAllLongSales($field, $acc);
        $summs = [];
        foreach ($rows as $line) {
            if (!isset($summs[$line['M']][$line['C']])) {
                $summs[$line['M']][$line['C']] = 0;
            }

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
                } elseif ($S > 25000 && $S <= 50000) {
                    if (!isset($result[1][$M]['summ'])) {
                        $result[1][$M]['summ'] = 0;
                        $result[1][$M]['count'] = 0;
                    }
                    $result[1][$M]['summ'] += $S;
                    $result[1][$M]['count'] += 1;
                } else {
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
                $result2[$key][$M] = $values['summ'] / $values['count'];
            }
        }

        return $result2;
    }

    public function getAverageDataByManagers()
    {
        $return = [];
        $ms = Yii::app()->accDispatcher->getTriggerGroupList(CrmAccessDispatcher::GROUP_SEO);
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


    public function getStatsCurrentMonth()
    {
        $DS = $this->getBaseStructureQuery($this->mEnd - 1, $this->mEnd);

        $DS->selectDistinct("
    			sum({$this->getField('vd')}) as S,
    			P.FK_sale_product_kind * 1000 + E.FK_feature1 as criteria,
				S.FK_company as company,
    			S.FK_control_month as M,
				P.FK_performer as Prf,
				S.ID as sid,
    			P.is_unique as is_unique,
    			E.is_long as is_long
    			")
            ->group('M, criteria, company');

        $DS = $this->filterMarkedShorts($DS, false);
        $DS = $this->filterSeo($DS);

        //Группируес по месяцам
        $products = $DS->queryAll();
        $arrProdGroupsByMonth = [];
        foreach ($products as $sale) {
            $arrProdGroupsByMonth[$sale['M']][] = $sale;
        }

        $prevMonthCompanies = [];
        foreach ($arrProdGroupsByMonth[$this->mEnd - 1] as $item) {
            if (!in_array($item['company'], $prevMonthCompanies)) {
                $prevMonthCompanies[] = $item['company'];
            }
        }

        $currentMonthCompanies = [];
        foreach ($arrProdGroupsByMonth[$this->mEnd] as $item) {
            if (!in_array($item['company'], $currentMonthCompanies)) {
                $currentMonthCompanies[] = $item['company'];
            }
        }

        //TODO: Есть небольшой нюанс. Проверяется только по предыдущему месяцу. Уточнить, как проверять корректно и исправить
        $newProducts = [];
        foreach ($arrProdGroupsByMonth[$this->mEnd] as $item) {
            if (!in_array($item['company'], $prevMonthCompanies)) {
                $newProducts[] = $item;
            }
        }

        //TODO: Есть небольшой нюанс. Проверяется только по предыдущему месяцу. Уточнить, как проверять корректно и исправить
        $lossesProducts = [];
        foreach ($arrProdGroupsByMonth[$this->mEnd - 1] as $item) {
            if (!in_array($item['company'], $currentMonthCompanies)) {
                $lossesProducts[] = $item;
            }
        }

        $result = ['new' => 0, 'los' => 0, 'total' => 0];
        foreach ($newProducts as $product) {
            $result['new'] += $product['S'];
        }

        foreach ($lossesProducts as $product) {
            $result['los'] += $product['S'];
        }

        $result['total'] = $result['new'] - $result['los'];
        $result['month'] = Month::getMonthName($this->mEnd);
        $result['debug']['new'] = $newProducts;
        $result['debug']['los'] = $lossesProducts;

        return $result;

    }
}