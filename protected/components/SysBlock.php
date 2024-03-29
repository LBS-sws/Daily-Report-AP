<?php
class SysBlock {
	protected $checkItems;
	
	public function __construct() {
		$this->checkItems = require(Yii::app()->basePath.'/config/sysblock.php');
	}
	
	public function blockNRoute($controllerId, $functionId) {
		$session = Yii::app()->session;
		$sysblock =isset($session['sysblock']) ? $session['sysblock'] : array();
		$sysId = Yii::app()->params['systemId'];
		
		foreach ($this->checkItems as $key=>$value) {
			if (!isset($sysblock[$key]) || $sysblock[$key]==false) {
				$result = call_user_func('self::'.$value['validation']);
				$sysblock[$key] = $result;
				$session['sysblock'] = $sysblock;
				
				if (!$result) {
					$url = '';
					$systems = General::systemMapping();
					if ($sysId==$value['system']) {
						if ($controllerId!='site' && $functionId!=$value['function']) $url = $systems[$value['system']]['webroot'];
					} else {
						$url = $systems[$value['system']]['webroot'];
					}
					return ($url=='' ? false : $url);
				}
			}
		}
		
		return false;
	}
	
	public function getBlockMessage($systemId) {
		$session = Yii::app()->session;
		if (isset($session['sysblock'])) {
			foreach ($session['sysblock'] as $key=>$value) {
				if (!$value && isset($this->checkItems[$key])) {
					if ($this->checkItems[$key]['system']==$systemId) return $this->checkItems[$key]['message'];
				}
			}
		}
		return false;
	}

    /**
     * 驗證管理員是否有未考核的員工.
     * @param string $uid 需要被驗證的管理員..
     * @return bool true(無未考核員工)  false(有未考核員工).
     */
	public function validateReviewLongTime(){
		$uid = Yii::app()->user->id;
		$suffix = Yii::app()->params['envSuffix'];
		$row = Yii::app()->db->createCommand()->select("b.id")->from("hr$suffix.hr_binding a")
			->leftJoin("hr$suffix.hr_employee b","a.employee_id=b.id")
			->leftJoin("security$suffix.sec_user_access e","a.user_id=e.username")
			->where("a.user_id=:user_id and a_read_write like'%RE02%'",array(":user_id"=>$uid))->queryRow();
		if($row){ //賬號有綁定的員工且有考核權限
			$year = date("Y");
			$day = date("m-d");
            $dateSql = " and b.id<0";
            if($year>2020){
                if($year == 2021&&$day>="02-01"&&$day<"08-01"){
                    $dateSql = " and ((b.year<=".($year-1).") or (b.year = $year and b.year_type = 1))";
                }elseif ($day>="08-01"){
                    $dateSql = " and (b.year = $year and b.year_type = 1)";
                }elseif ($day>="02-01"){
                    $dateSql = " and (b.year = ".($year-1)." and b.year_type = 2)";
                }
            }
			$count = Yii::app()->db->createCommand()->select("a.id")->from("hr$suffix.hr_review_h a")
				->leftJoin("hr$suffix.hr_review b","a.review_id=b.id")
				->leftJoin("hr$suffix.hr_employee d","b.employee_id=d.id")
				->where("d.staff_status=0 and a.status_type!=3 and a.handle_id=:handle_id $dateSql",
					array(":handle_id"=>$row['id'])
				)->queryRow();
			if($count){ //存在未考核的員工
				return false;
			}
		}
		return true;
	}
	
	/** 
		每月10日, 驗證 用户还没有提交上月营业报告, false: 还没有提交
	**/
	public function isSalesSummarySubmitted() {
		$uid = Yii::app()->user->id;
		$city = Yii::app()->user->city();
		$suffix = Yii::app()->params['envSuffix'];
		$lastdate = date('d')<10 ? date('Y-m-d',strtotime('-2 months')) : date('Y-m-d',strtotime('last day of previous month'));
		$year = date("Y", strtotime($lastdate));
		$month = date("m", strtotime($lastdate));
		
		$sql = "select username from security$suffix.sec_user_access 
				where username='$uid' and system_id='ops' and a_read_write like '%YA01%'
			";
		$row = Yii::app()->db->createCommand($sql)->queryRow();
		if ($row===false) return true;

		$citylist = General::getCityListWithNoDescendant();
		if (!array_key_exists($city, $citylist)) return true;
		
		$sql = "select workflow$suffix.RequestStatus('OPRPT',a.id,a.lcd) as wfstatus
				from operation$suffix.opr_monthly_hdr a 
				where a.city='$city' and a.year_no=$year and a.month_no=$month and a.status='Y'
			";
		$row = Yii::app()->db->createCommand($sql)->queryRow();
		return ($row===false || ($row['wfstatus']!='' && $row['wfstatus']!='PS'));
	}
		
	/** 
		每月10日, 驗證 地区主管未审核营业报告, false: 未审核
	**/
	public function isSalesSummaryApproved() {
		$uid = Yii::app()->user->id;
		$city = Yii::app()->user->city();
		$suffix = Yii::app()->params['envSuffix'];
		$lastdate = date('d')<10 ? date('Y-m-d',strtotime('-2 months')) : date('Y-m-d',strtotime('last day of previous month'));
		$year = date("Y", strtotime($lastdate));
		$month = date("m", strtotime($lastdate));
		
		$sql = "select a_control from security$suffix.sec_user_access 
				where username='$uid' and system_id='ops' and a_read_write like '%YA03%'
			";
		$row = Yii::app()->db->createCommand($sql)->queryRow();
		if ($row===false) {
			return true;
		} else {
			if (strpos($row['a_control'],'YN01')!==false) return true;
		}

		$wf = new WorkflowOprpt;
		$wf->connection = Yii::app()->db;
		$list = $wf->getPendingRequestIdList('OPRPT', 'PH', $uid);
		if (empty($list)) return true;
		
		$sql = "select a.id
				from operation$suffix.opr_monthly_hdr a 
				where a.id in ($list) and a.year_no=$year and a.month_no=$month and a.status='Y' 
				limit 1
			";
		$row = Yii::app()->db->createCommand($sql)->queryRow();
		return ($row===false);
	}

	/** 
		每年12月30日, 驗證 用户有学分确认权限的未及时处理完, false: 未处理
	**/
	public function isCreditConfirmed() {
		$uid = Yii::app()->user->id;
		$city = Yii::app()->user->city();
        $city_allow = Yii::app()->user->city_allow();
		$suffix = Yii::app()->params['envSuffix'];
		$lastdate = date('m-d')=='12-31' ? date('Y-m-d') : date('Y-m-d',strtotime('last year December 31st'));
		$year = date("Y", strtotime($lastdate));
		$month = date("m", strtotime($lastdate));

		$sql = "select a_control from security$suffix.sec_user_access 
				where username='$uid' and system_id='sp' and a_read_write like '%GA04%'
			";
		$row = Yii::app()->db->createCommand($sql)->queryRow();
		if ($row===false) return true;

		$sql = "select a.id from spoint$suffix.gr_credit_request a
                LEFT JOIN spoint$suffix.gr_credit_type b ON a.credit_type = b.id
                LEFT JOIN hr$suffix.hr_employee d ON a.employee_id = d.id
                where d.city='$city' AND a.state = 1 and a.apply_date <= '$lastdate'
				limit 1
			";
		$row = Yii::app()->db->createCommand($sql)->queryRow();
		return ($row===false);
	}

	/** 
		每年12月30日, 驗證 用户有学分审核权限的未及时处理完, false: 未处理
	**/
	public function isCreditApproved() {
		$uid = Yii::app()->user->id;
		$city = Yii::app()->user->city();
        $city_allow = Yii::app()->user->city_allow();
		$suffix = Yii::app()->params['envSuffix'];
		$lastdate = date('m-d')=='12-31' ? date('Y-m-d') : date('Y-m-d',strtotime('last year December 31st'));
		$year = date("Y", strtotime($lastdate));
		$month = date("m", strtotime($lastdate));

		$sql = "select a_control from security$suffix.sec_user_access 
				where username='$uid' and system_id='sp' and a_read_write like '%GA01%'
			";
		$row = Yii::app()->db->createCommand($sql)->queryRow();
		if ($row===false) return true;

		$sql = "select a.id from spoint$suffix.gr_credit_request a
                LEFT JOIN spoint$suffix.gr_credit_type b ON a.credit_type = b.id
                LEFT JOIN hr$suffix.hr_employee d ON a.employee_id = d.id
                where d.city='$city' AND a.state = 4 and a.apply_date <= '$lastdate'
				limit 1
			";
		$row = Yii::app()->db->createCommand($sql)->queryRow();
		return ($row===false);
	}


    /**
    每月3日, 驗證 用户有月报表分析权限为读写的权限的未及时发送邮件, false: 未处理
     **/
    public function isMonthDispatch () {
        $uid = Yii::app()->user->id;
        $city = Yii::app()->user->city();
        $suffix = Yii::app()->params['envSuffix'];
        $email=Yii::app()->user->email();
        $year = date('Y');
        $day = date('d');
        $month = date('n');
        if($year==2022&&$month==2){//2022年春节处理(2022年2月14日)
            if($day<14){
                $lastdate = date('Y-m-d',strtotime(date('Y-m-14').' -3 months'));
            }else{
                $lastdate = date('Y-m-d',strtotime(date('Y-m-15').' -2 months'));
            }
        }elseif(in_array($month,array(10,5))){//（国庆、五一）特殊处理
            if($day<10){
                $lastdate = date('Y-m-d',strtotime(date('Y-m-10').' -3 months'));
            }else{
                $lastdate = date('Y-m-d',strtotime(date('Y-m-11').' -2 months'));
            }
        }elseif($day<3){ //每月三号以后限制两个月以前的报表汇总
            $lastdate = date('Y-m-d',strtotime(date('Y-m-3').' -3 months'));
        }else{
            $lastdate = date('Y-m-d',strtotime(date('Y-m-4').' -2 months'));
        }
	    if($lastdate<'2022-12-01'){//十二月份以前的報表不限制使用
		    return true;
	    }
        $year = date("Y", strtotime($lastdate));
        $month = date("n", strtotime($lastdate));
        $sql = "select a_control from security$suffix.sec_user_access 
				where username='$uid' and system_id='drs' and a_read_write like '%H01%'
			";
        $row = Yii::app()->db->createCommand($sql)->queryRow();
        if ($row===false) return true;
        $subject="月报表总汇-" .$year.'/'.$month;
        $star = date("Y-m-01", strtotime($lastdate));
        $end = date("Y-m-t", strtotime($lastdate));
        $sql = "select * from swoper$suffix.swo_month_email               
                where city='$city' and  request_dt>= '$star' and  request_dt<= '$end' and subject='$subject' 	
			";
        $row = Yii::app()->db->createCommand($sql)->queryAll();
       if(count($row)>=1){
           return true;
       }else{
           return false;
       }
    }


	public function test() {
		return false;
	}
}
?>
