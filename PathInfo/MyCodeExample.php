<?php 

class MixingUnitCalcCompanyid4 extends MixingUnitCalc {

    /** @var MixingUnitCalcCompanyid4 количество точек графика клапана */
    const NUMBER_OF_VALVE_POINTS = 12;

    /** @var MixingUnitCalcCompanyid4 максимальная температура для узлов СУ3, выше уже узлы СУ3/130 */
    const MAX_TEMP_FOR_SU3 = 95;

    /**
     * @param $build_id
     * @param bool $debug
     * @return array
     * Начинаем расчет
     */
    public static function calc($build_id, $debug = false) {
        $calculator = new self($build_id);
        return $calculator->calculate();
    }

    /**
     * @return array
     * Сначала будем пытаться подобрать узел в сборе
     * Если не получится, будем пытаться подобрать в разбре
     * Подход к подбору в обоих случаях разный
     */
    protected function calculate() {
        $sections = FunctionsForCalcVentUnit::getArrayOfSections($this->data);
        foreach ($sections['HW'] as $heater) {
            $waterFlow = $this->getWaterFlow($heater);
            $pressure = $this->getPressure($heater);
            if ($valve = $this->calcAssemblyMixingUnit($waterFlow, $pressure)) { // сначала попробуем посчитать узел в сборе
                $this->addAutomatics($heater['airDirection'], $heater['sectionNum'], $heater, $valve, $valve['rk_pumps'], $pressure);
            } else {
                $calcRes = $this->calcValve($waterFlow, $pressure);
                if ($calcRes['valve']) {
                    $valve = $calcRes['valve'];
                    $valvePressure = $calcRes['pressure'];
                    $pump = $this->findPump($pressure + $valvePressure, $waterFlow, $valve->pump);
                    if ($pump) {
                        $this->addAutomatics($heater['airDirection'], $heater['sectionNum'], $heater, $valve, $pump, $pressure);
                    }
                }
            }
            $this->currentSection += 1;
        }
        foreach ($sections['CW'] as $cooler) {
            $waterFlow = $this->getWaterFlow($cooler);
            $pressure = $this->getPressure($cooler);
            $calcRes = $this->calcValve($waterFlow);
            if ($calcRes['valve']) {
                $valve = $calcRes['valve'];
                $this->addAutomatics($cooler['airDirection'], $cooler['sectionNum'], $cooler, $valve, null, $pressure);
            }
            $this->currentSection += 1;
        }
        return $this->getAutomatics();
    }

    /**
     * @param float $waterFlow
     * @param float $heaterPressure
     * Выберем все узлы, которые идут в сборе с клапаном
     */
    protected function getAssemblyValves($waterFlow, $heaterPressure) {

        $criteria = new CDbCriteria;
        $criteria->addCondition('t.type = "MST"');
        $criteria->compare('t.companyid', Yii::app()->user->companyid);
        $criteria->addCondition('t.deleted = 0');
        $criteria->order = 't.kvs, t.name, p.max_flow';
        $criteria->join = 'JOIN rk_pumps p ON t.pump = p.type AND p.deleted = 0 AND p.companyid = '
            .Yii::app()->user->companyid.' AND p.max_flow >= '.$waterFlow.' AND p.max_pressure >= '.$heaterPressure;
        return MixingUnitsTable::model()->with('rk_pumps')->findAll($criteria);
    }

    /**
     * @param float $waterFlow - расход м3/ч
     * @param float $heaterPressure - потери давления кПа
     * @return array - подобранные насос и клапан
     * Попытка расчета смесительного узла в сборе
     */
    protected function calcAssemblyMixingUnit($waterFlow, $heaterPressure) {
        $valves = $this->getAssemblyValves($waterFlow, $heaterPressure);
        foreach ($valves as $valve) {
            $polynomial = $this->getPolynomialCoef($valve['rk_pumps']);
            if ($this->isPointBelowGraph($waterFlow, $heaterPressure, $polynomial)) { // если точка ниже графика
                $speedCalc = new FanSpeedCalculator($polynomial, 100);
                $calc = new FanMainPointCalculatorAtSpeed($speedCalc, 100, 0, $valve['rk_pumps']['max_flow']);
                $xLintersection = $calc->calculate($waterFlow, $heaterPressure); // проведем через точку параболу до пересечения с кривой насоса
                $mixingUnitPressure = $this->getPolynominalValue($xLintersection, $polynomial); // найдем значение полинома
                $valvePressure = $this->getAssemblyValvePressure($xLintersection, $valve); // найдем потери на клапане
                $k = $valvePressure / ($heaterPressure + $mixingUnitPressure);
                if ($valvePressure >= $heaterPressure && $k >= 0.5 && $k <= 1) {
                    return $valve;
                }
            }
        }
        return [];
    }

    /**
     * @param float $waterFlow
     * @param float $heaterPressure
     * @param array $polynomial
     * @return bool
     * Првоерим ниже точка, чем график или нет
     */
    protected function isPointBelowGraph($waterFlow, $heaterPressure, $polynomial) {
        $pumpPressure = $this->getPolynominalValue($waterFlow, $polynomial);
        return $pumpPressure > $heaterPressure;
    }

    /**
     * @param float $x
     * @param array $valve
     * @return float|int
     * Посчитаем потери на клапане
     */
    protected function getAssemblyValvePressure($x, $valve) {
        for ($i = 2; $i <= self::NUMBER_OF_VALVE_POINTS; $i++) {
            if ($valve['x'.($i - 1)] <= $x && $x <= $valve['x'.$i]) {
                $x0 = $valve['x'.($i - 1)];
                $x1 = $valve['x'.$i];
                $y0 = $valve['y'.($i - 1)];
                $y1 = $valve['y'.$i];
                return ($y1 - $y0) * ($x - $x0) / ($x1 - $x0) + $y0;
            }
        }
        return 0;
    }

    /**
     * @param array $pump
     * @return array
     * Получим коэффицинты полинома
     */
    protected function getPolynomialCoef($pump) {
        return [
            $pump['k0'], $pump['k1'], $pump['k2'], $pump['k3'], $pump['k4'], $pump['k5']
        ];
    }

    /**
     * @param float $x
     * @param array $polynomial
     * @return float|int
     * Получим значение полинома насоса в заданной точке
     */
    protected function getPolynominalValue($x, $polynomial) {
        $y = 0.0;
        foreach ($polynomial as $power => $val) {
            $y += ( $val * pow($x, $power) );
        }
        return $y;
    }

    /**
     * @param float $waterFlow
     * @return mixed
	 * Вытащим клапаны из БД
     */
    protected function getValves($waterFlow) {
        return Yii::app()->db->createCommand()
            ->select('*')
            ->from('rk_mixing_units')
            ->order('kvs')
            ->where('y1 < :waterFlow AND deleted = 0 AND companyid = :cid', array(
                'cid' => Yii::app()->user->companyid,
                'waterFlow' => $waterFlow
            ))
            ->andWhere('type <> "MST"')
            ->queryAll();
    }

    /**
     * @param array $valve
     * @param array $section
     * @return string
	 * Получим название клапана
     */
    protected function getValveName($valve, $section) {
        if ($valve && $valve->type == 'MST') {
            if ($section['params_for_calculation'][0]['waterInletTemp'] > self::MAX_TEMP_FOR_SU3) {
                return $valve->name . '/130';
            }
        }
        return false;
    }

}