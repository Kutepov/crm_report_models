<?php

class TimeManagementReportForm extends CFormModel
{
    /**
     * @return CDbCriteria
     */
    private function getBaseStructureQuery()
    {
        $criteria = new CDbCriteria;
        $criteria->with = ['manager'];
        $criteria->mergeWith(['join' => 'LEFT JOIN {{managers}} u ON u.ID = t.FK_manager']);
        $criteria->mergeWith(['join' => 'RIGHT JOIN {{sales_products}} sp ON sp.ID = t.FK_sale_product']);
        $criteria->mergeWith(['join' => 'LEFT JOIN {{sales}} s ON s.ID = sp.FK_sale']);
        $criteria->mergeWith(['join' => 'LEFT JOIN {{sales_summaries}} sum ON sum.FK_sale = s.ID']);
        $criteria->mergeWith(['join' => 'LEFT JOIN {{companies}} company ON company.ID = s.FK_company']);
        //В выборку попадают только оплаченные или подтвержденные продажи
        $criteria->addCondition('(sum.payed - sum.total) >= 0 OR s.W_lock = "Y"', 'AND');
        //В ывборку не попадают продукты с ВД <= 0
        $criteria->addCondition($this->getFieldVD().' > 0');
        //Неотмененные продажи и ПП
        $criteria->addCondition('s.active = 1');
        $criteria->addCondition('sp.active = 1');
        //ПП DEV не учавствуют в выборке (FK_department = 2 - Веб отдел)
        $criteria->addCondition('u.FK_department != 6');

        if (Yii::app()->request->getParam('search')) {
            $criteria->mergeWith(['join' => 'LEFT JOIN {{spk_elaborations}} elaboration ON elaboration.ID = sp.FK_sale_prod_elaboration']);
        }

        if (!Yii::app()->request->getParam('SaleProductPerformer_sort')) {
            $criteria->order = 's.FK_control_month DESC, sp.ID DESC';
        }

        $criteria->group = 'sp.ID';

        return $criteria;
    }

    /**
     * Возвращает поле расчета ВД продукта из базы
     * @return string
     */
    private function getFieldVD() : string
    {
        return '((1-(sp.outlay_VZ+sp.discost)/100) * sp.total/(1-sp.discost/100))';
    }

    /**
     * Возвращает поле расчета ВД исполнителя из базы
     * @return string
     */
    private function getFieldPerformerVD() : string
    {
        return '(((1-(sp.outlay_VZ+sp.discost)/100) * sp.total/(1-sp.discost/100))/sp.amount*t.hours)';
    }

    /**
     * @param CDbCriteria $criteria
     * @param array $filter
     * @return bool
     * @throws Exception
     */
    private function applyFilters(CDbCriteria &$criteria, array $filter, bool $new_report = false) : bool
    {
        if ((bool)!$filter['iam_responsible_sale']) {
            if ($filter['performer'] && (bool)!$filter['iam_responsible_sale']) {
                $criteria->addCondition('t.FK_manager = ' . $filter['performer']);
            } else {
                $availableUsers = User::getManagersForReportTime((bool)!$filter['with_dead'], $new_report);
                $ids = [];
                foreach ($availableUsers as $id => $fio) {
                    $ids[] = $id;
                }
                $criteria->addInCondition('t.FK_manager', $ids);
                $criteria->group = 'sp.ID';
            }
        }

        if ($filter['month'] && !$filter['month_after'] && !$filter['month_before'] && !$filter['month_fou']) {
            $criteria->addCondition('s.FK_control_month = '.$filter['month']);
        }
        if ($filter['fou_check']) {
            $criteria->mergeWith(['join' => 'LEFT JOIN {{services_provided}} as fou ON fou.FK_sale_product = sp.ID']);
            $cond = $filter['fou_check'] == 'is_fou' ? 'NOT' : '';
            $criteria->addCondition('fou.ID IS '.$cond.' NULL');
        }
        if ($filter['month_fou']) {
            $command = Yii::app()->db->createCommand();
            $command->selectDistinct('s.ID')
                ->from('{{services_provided}} as fou')
                ->leftJoin('{{sales_products}} as sp', 'fou.FK_sale_product = sp.ID')
                ->leftJoin('{{sales}} as s', 's.ID = sp.FK_sale')
                ->leftJoin('{{bills}} as b', 'b.FK_sale = s.ID')
                ->leftJoin('{{acts}} as a', 'a.FK_bill = b.ID')
                ->where('month(a.act_date) = '.Month::getMonthIndex($filter['month_fou'], 'M').' AND year(a.act_date) = '.Month::getMonthIndex($filter['month_fou'], 'Y'));
            $result = $command->queryAll();

            $saleIds = [];
            foreach ($result as $item) {
                $saleIds[] = $item['ID'];
            }

            $criteria->addInCondition('s.ID', $saleIds);
        }
        if ($filter['month_before']) {
            $criteria->addCondition('s.FK_control_month <= '.$filter['month_before']);
        }

        if ($filter['month_after']) {
            $criteria->addCondition('s.FK_control_month >= '.$filter['month_after']);
        }

        if ($filter['end_date']) {
            $innerDate = Month::getInnerDate($filter['end_date'], '');
            $innerDate = explode('-', $innerDate);
            $year = (int)$innerDate[0];
            $month = (int)$innerDate[1];
            $criteria->addCondition('year(sp.end_date) = '.$year.' and month(sp.end_date) = '.$month);
        }

        if ($filter['is_close'] == 'yes') {
            $criteria->addCondition('s.W_lock = "Y"');
        }

        if ($filter['is_close'] == 'no') {
            $criteria->addCondition('s.W_lock = "N" ');
        }

        if ($filter['company']) {
            $criteria->mergeWith(['join' => 'LEFT JOIN {{companies}} c ON s.FK_company = c.ID']);
            $criteria->addCondition('c.ID = '.$filter['company']);
        }

        if ($filter['acc_manager']) {
            $criteria->addCondition('s.FK_acc_manager = '.$filter['acc_manager']);
        }

        if ((bool)$filter['iam_responsible_sale']) {
            $criteria->addCondition('s.FK_acc_manager = '.Yii::app()->user->id);
        }

        if ($filter['department']) {
            $criteria->addCondition('u.FK_department = '.$filter['department']);
        }

        if ($filter['search']) {
            $criteria->addCondition('company.name LIKE "%'.$filter['search'].'%" OR s.name LIKE "%'.$filter['search'].'%" OR elaboration.name LIKE "%'.$filter['search'].'%"');
        }

        if ($filter['act_state']) {
            $criteria->mergeWith(['join' => 'LEFT JOIN {{bills}} bill ON bill.FK_sale = s.ID']);
            $criteria->mergeWith(['join' => 'LEFT JOIN {{acts}} act ON act.FK_bill = bill.ID']);
            if ($filter['act_state'] == 'no') {
                $criteria->addCondition('not exists (select * from {{acts}} a where a.FK_bill = bill.ID)');
                //$criteria->addCondition('act.FK_act_state = 9');
            }
            else {
                $criteria->addCondition('act.FK_act_state = ' . $filter['act_state']);
                $criteria->addCondition('act.active = 1');
            }
        }

        return true;
    }

    /**
     * @param array $filter
     * @return CDbCriteria
     * @throws Exception
     */
    private function getCriteria(array $filter = [], bool $new_report = false) : CDbCriteria
    {
        $criteria = $this->getBaseStructureQuery();
        $this->applyFilters($criteria, $filter, $new_report);
        return $criteria;
    }

    /**
     * @param array $filter
     * @return CActiveDataProvider
     * @throws Exception
     */
    public function getItems(array $filter = []) : CActiveDataProvider
    {
        $new_report = (bool)$_POST['new_report'];
        $criteria = $this->getCriteria($filter, $new_report);
        $dp = new CActiveDataProvider( 'SaleProductPerformer', [
            'criteria' => $criteria,
            'pagination' => [
                'pageSize' => 30
            ]
        ]);

        if (!Yii::app()->request->getPost('performer')) {
            $dp->sort = ['attributes' => ['sp.amount', 'company.name']];
        }
        else {
            $dp->sort = ['attributes' => ['hours', 'company.name']];
        }

        return $dp;
    }

    /**
     * @param array $filter
     * @return array
     * @throws Exception
     */
    public function getTotals(array $filter = []) : array
    {
        $new_report = (bool)$_POST['new_report'];
        $new_report2 = (bool)$_POST['new_report2'];
        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(sp.cost * sp.amount) as val, sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        $model = SaleProductPerformer::model()->find($criteria);
        $result = ['hours' => round($model->total_hours, 2), 'VD' => round($model->VD, 2), 'val' => $model->val];

        $criteria->group = 'sp.ID';
        $criteria->select = '*';
        $products = SaleProductPerformer::model()->findAll($criteria);

        $ids = [];
        foreach ($products as $product) {
            $ids[] = $product->FK_sale_product;
        }

        unset($filter['performer']);
        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        $criteria->addCondition('u.FK_department = 3');
        if ($ids) {
            $criteria->addCondition('sp.ID in ('.implode(',', $ids).')');
        }
        $model = SaleProductPerformer::model()->find($criteria);
        $result += [3 => $model ? $model->total_hours : 0];

        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        if ($ids) {
            $criteria->addCondition('sp.ID in ('.implode(',', $ids).')');
        }
        $criteria->addCondition('u.FK_department = 4');
        $model = SaleProductPerformer::model()->find($criteria);
        $result += [4 => $model ? $model->total_hours : 0];

        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        if ($ids) {
            $criteria->addCondition('sp.ID in ('.implode(',', $ids).')');
        }
        $criteria->addCondition('u.FK_department = 5');
        $model = SaleProductPerformer::model()->find($criteria);
        $result += [5 => $model ? $model->total_hours : 0];

        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        if ($ids) {
            $criteria->addCondition('sp.ID in ('.implode(',', $ids).')');
        }
        $criteria->addCondition('u.FK_department = 18');
        $model = SaleProductPerformer::model()->find($criteria);
        $result += [18 => $model ? $model->total_hours : 0];

        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        if ($ids) {
            $criteria->addCondition('sp.ID in ('.implode(',', $ids).')');
        }
        $criteria->addCondition('u.FK_department = 9');
        $model = SaleProductPerformer::model()->find($criteria);
        $result += [9 => $model ? $model->total_hours : 0];

        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        if ($ids) {
            $criteria->addCondition('sp.ID in ('.implode(',', $ids).')');
        }
        $criteria->addCondition('u.FK_department in (17)');
        $model = SaleProductPerformer::model()->find($criteria);
        $result += [17 => $model ? $model->total_hours : 0];

        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        if ($ids) {
            $criteria->addCondition('sp.ID in ('.implode(',', $ids).')');
        }
        $criteria->addCondition('u.FK_department in (1)');
        $model = SaleProductPerformer::model()->find($criteria);
        $result += [1 => $model ? $model->total_hours : 0];

        $criteria = $this->getCriteria($filter, $new_report);
        $criteria->select = 'sum(t.hours) as total_hours, sum('.$this->getFieldPerformerVD().') as VD, "group" as group_field';
        $criteria->group = 'group_field';
        if ($ids) {
            $criteria->addCondition('sp.ID in ('.implode(',', $ids).')');
        }
        $criteria->addCondition('t.FK_manager = '.Yii::app()->user->id);
        $model = SaleProductPerformer::model()->find($criteria);
        $result += ['iam_vd' => round($model->VD, 2)];

        if ($new_report2 && count($_POST) >= 3) {
            $criteria = $this->getCriteria($filter, $new_report);
            $models = SaleProductPerformer::model()->findAll($criteria);

            $acts_sum = 0;
            $pay_sum = 0;
            $min_sum = 0;
            foreach ($models as $model) {
                $actPercent = $model->sale_product->sale->getActPercent(1) / 100;
                $salePercent = $model->sale_product->sale->getColumnTotals('payment') / $model->sale_product->sale->getColumnTotals('total');
                $acts_sum += round($model->sale_product->calcVD() * ($actPercent), 2);
                $pay_sum += round($model->sale_product->calcVD() * ($salePercent), 2);

                $minPercent = $actPercent > $salePercent ? $salePercent : $actPercent;

                $min_sum += round($model->sale_product->calcVD() * ($minPercent), 2);


            }

            $result += ['acts_sum' => $acts_sum, 'pay_sum' => $pay_sum, 'min_sum' => $min_sum];
        }

        return $result;
    }

    /**
     * @param array $filter
     * @return array
     * @throws Exception
     */
    public function getIdsProducts(array $filter) : array
    {
        $criteria = $this->getCriteria($filter);
        $criteria->select = 'sp.ID as pid';
        $criteria->group = 'sp.ID';
        $models = SaleProductPerformer::model()->findAll($criteria);
        $result = [];
        foreach ($models as $item) {
            $result[] = $item->pid;
        }
        return $result;
    }
}