<?php

if (!defined("BASEPATH"))
    exit("No direct script access is allowed");

class Plan_model extends CI_Model {

    private $table = 'assemblies_on_plan';

    public function __construct() {
        parent::__construct();
    }

    /*
     * Get the Project related plan Assemblies 
     * parameters: where(array)
     */

    function geetPlanAssembles($where = array(), $groupby = false) {

        $this->db->select("*");
        $this->db->from("$this->table ap");
        $this->db->join('assemblies a', 'a.assembly_id = ap.plan_assembly_id', 'left');
        $this->db->where($where);
        if ($groupby)
            $this->db->group_by('a.assembly_id');
        $query = $this->db->get();

        if ($query->num_rows() > 0) {
            return $query->result_array();
        } else {
            return FALSE;
        }
    }

    function getPlanSchedules($project_id) {
        $query = " SELECT ps.*,a.assembly_name,assembly_opaque_icon_path,assembly_green_icon_path,assembly_red_icon_path,assembly_transparent_icon_path,assembly_icon_type FROM project_schedule ps
            
        JOIN assemblies_on_plan aop ON aop.plan_assembly_plan_id = ps.plan_assembly_plan_id
        
        JOIN assemblies a ON a.assembly_id = aop.plan_assembly_id

        WHERE assembly_icon_type !='purple' AND ps.project_id=" . $project_id . " GROUP BY ps.schedule_id";
        return $this->db->query($query)->result_array();
    }

    function getPlanAssemblies($where) {
        $query = "SELECT * FROM assemblies a JOIN assemblies_on_plan ap ON ap.plan_assembly_id = a.assembly_id WHERE  $where GROUP BY ap.plan_assembly_plan_id ORDER BY assembly_type ";
        return $this->db->query($query)->result();
    }

    function updatePlanAssembly($where, $data) {
        return $this->db->where($where)->update($this->table, $data);
    }

    /*
     * @purpose: To Insert the plan_assembly data
     * @return : insertid
     */

    function insertPlanAssembly($data) {
        $this->db->insert($this->table, $data);
        return $this->db->insert_id();
    }

    function updatePdfStatus($project_id, $status = null, $time="") {
        $this->db->where(array('project_id' => $project_id))->update('projects', array('generatingPdf' => $status,'lastgeneratedPdf'=>$time));
    }

    /*
     * get the Project Assemblies using legend to group by green & other assemblies as one
     * added assembly_icon_type on 14-5-2016
     */
    function getProjectLegendAssemblies($project_id, $where = ""){
        $query = "SELECT *,CASE WHEN is_duplicate_assembly = 'yes'
		AND assembly_icon_type = 'customer_override' 
		THEN assembly_red_icon_path
		WHEN is_duplicate_assembly = 'yes'
		THEN assembly_green_icon_path ELSE assembly_opaque_icon_path END AS result 
            FROM assemblies a
            JOIN assemblies_on_plan ab ON ab.`plan_assembly_id` = a.assembly_id
            WHERE project_id = $project_id AND assembly_icon_type !=  'transparent' AND legend != '' $where
            GROUP BY result ORDER BY a.assembly_id";
        
        return $this->db->query($query)->result_array();
    }
    
    function generatePlanPdf($projectId) {
	
	//echo "hello"; exit;
        
        //check if file is already exist then display directly.
        $selectedPlan = $this->db->where(array('projectid' => $projectId, 'type' => 'selected_plan'))->get('project_files')->row();
        if (empty($selectedPlan)) {

            //check if plan is already started then check the flag is true or not
            $pd = $this->common->checkPdfGenerated($projectId);
            if ($pd == 1)
                return false;
            //get the plan images
            $planimages = $this->db->where(array('projectid' => $projectId, 'type' => 'plan_images'))->get('project_files')->result();

            if (empty($planimages)) {
                return false;
            } else {
                $time = time();
                $this->updatePdfStatus($projectId, 1,$time);
            }

            $images = "";
            $tempPath = PROJECT_FOLDER . $projectId . "/temp".time()."/";
            
            //create a temp[ folder under the prjects folder
            if (!file_exists($tempPath)) {
                mkdir($tempPath, 0777, true);
            }

            $projectAssemblies = $this->getProjectLegendAssemblies($projectId," AND assembly_icon_type != 'purple' ");

            foreach ($planimages as $plan) {

                //get the project related plan assemblies from assemblies_on_plan table
                $where = array(
                    'plan_assembly_page_num' => $plan->id,
                    'project_id' => $projectId,
                    'assembly_icon_type != ' => 'transparent',
                    'assembly_icon_type !=' => 'purple'
                );
                $planAssemblies = $this->geetPlanAssembles($where);
                print_r($planAssemblies);exit;
                if (!empty($planAssemblies)) {
                    
                    $tempPlanImage = $tempPath . $plan->filename;
                    //copy the originat plan image to the temporary folder
                    copy(PROJECT_FOLDER . $projectId . "/" . $plan->filename, $tempPlanImage);
                    chmod($tempPlanImage, 0777);
                    $images [] = $tempPlanImage;
		     print_r($images); exit;
                    //$images .= $tempPlanImage;
                    $companyLogoPath = './img/electric_company_logos/';
                    $tempT = "convert ".$tempPlanImage." ";
                    $tempI = " ";
                    $tempOverlay = " ";
                    foreach ($planAssemblies as $assembly) {
                        if($assembly['assembly_asset_type'] == 'assembly'){
                            //check if the connected line is not 0
                            $p = (int) $assembly['connected_lines_json'];
                            //$p = 60;
                            if($p != 0 && 1==2){                                
                                if ($assembly['assembly_icon_type'] == 'opaque')
                                    $assemblyImage = $assembly['assembly_opaque_icon_path'];
                                else if ($assembly['assembly_icon_type'] == 'override')
                                    $assemblyImage = $assembly['assembly_green_icon_path'];
                                else if ($assembly['assembly_icon_type'] == 'transparent')
                                    $assemblyImage = $assembly['assembly_transparent_icon_path'];
                                else
                                    $assemblyImage = $assembly['assembly_red_icon_path'];
                                list($width, $height) = getimagesize('./img/icons/'.$assemblyImage);
                                $assembly['plan_assembly_width'] = ceil( ($p / 100)*$width);
                                $assembly['plan_assembly_height'] = ceil( ($p / 100)*$height);                                
                            }                            
                        }
                        if ($assembly['assembly_asset_type'] == 'logo') {
                            $companyLogo = $this->session->userdata('company_logo');
                            if ($companyLogo != '') {
                                $assemblyImage = $companyLogo;
                            }
                        } else if ($assembly['assembly_asset_type'] == 'connected_lines') {
                            if (!empty($assembly['connected_lines_json'])) {
                                $a_width = 6;                                
                                $json = json_decode($assembly['connected_lines_json'], true);
                                $path = $json[0]['path'];
                                if(isset($json[0]['stroke-width']) && $json[0]['stroke-width'] != "")
                                    $a_width = $json[0]['stroke-width'];
                                $output = '<!DOCTYPE html>
                                                <html>
                                                <body>

                                                <svg height="' . $assembly['plan_assembly_height'] . '" width="' . $assembly['plan_assembly_width'] . '">
                                                  <g fill="none">
                                                    <path stroke-width="'.$a_width.'" stroke-dasharray="5,5" stroke="' . $json[0]['stroke'] . '" d="' . $path . '" />
                                                  </g>
                                                </svg>
                                                </body>
                                                </html>';
                                $Temp_file_Path = PROJECT_FOLDER . $projectId . "/";
                                @unlink($Temp_file_Path."newfile.svg");
                                $myfile = fopen($Temp_file_Path . "newfile.svg", "w") or die("Unable to open file!");
                                fwrite($myfile, $output);
                                fclose($myfile);
                                chmod($Temp_file_Path . "newfile.svg", 0777);
                                $assemblyImage = $assembly['plan_assembly_plan_id'] . ".png";
                                exec("convert -background none " . $Temp_file_Path . "newfile.svg " . $tempPath . $assemblyImage);                                
                                chmod($tempPath . $assemblyImage, 0777);
                            }
                        } else if($assembly['assembly_asset_type'] == 'textbox'){
                            
                            $assemblyImage = $assembly['plan_assembly_plan_id'] . ".jpg";
                            $h = str_replace("px", "", $assembly['plan_assembly_height']);
                            $w = str_replace("px", "", $assembly['plan_assembly_width']);
                            //exec("convert -size ".$w."x".$h.' -bordercolor black  -border 1 xc:white -annotate +20+20 "'.$assembly['connected_lines_json'].'" ' .$tempPath . $assemblyImage);
                            exec("convert -size ".$w."x".
                                    $h.' -bordercolor white  -border 10 -pointsize 50  caption:"'.$assembly['connected_lines_json'].'" -bordercolor blue  -border 1 ' .$tempPath . $assemblyImage);
                            
                            chmod($tempPath . $assemblyImage, 0777);
                            
                        } else if($assembly['assembly_asset_type'] == 'line'){
				
                            list($x1,$x2) = explode("_",$assembly['plan_assembly_position_x_axis']);
                            list($y1,$y2) = explode("_",$assembly['plan_assembly_position_y_axis']);
                            /*$h = str_replace("px", "", $assembly['plan_assembly_height']);
                            //$h = $y2-$y1;
                            $w = str_replace("px", "", $assembly['plan_assembly_width']);
                            //$w = $x2-$x1;
                            $rotate = rad2deg($assembly['assembly_icon_rotate']);
                            $output = '<!DOCTYPE html>
                                                <html>
                                                <body><svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" xmlns="http://www.w3.org/2000/svg">

                                  <line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'"
                                      stroke-width="2" stroke="black"/>
                                      <g stroke="black" stroke-width="3" fill="black">
                               			 <circle id="pointA" cx="'.$x1.'" cy="'.$y1.'" r="3" />
     				      </g>

                                </svg></body></html>';
                            $svg = $assembly['plan_assembly_plan_id'] . ".svg";
                            $myfile = fopen($tempPath . $svg, "w") or die("Unable to open file!");
                            fwrite($myfile, $output);
                            fclose($myfile);
                            chmod($tempPath . $svg, 0777);
                            $assemblyImage = $assembly['plan_assembly_plan_id'] . ".png";

                            exec("convert -background none " . $tempPath . $svg." " . $tempPath . $assemblyImage);                                
                            chmod($tempPath . $assemblyImage, 0777);
                            /*$assemblyImage = $assembly['plan_assembly_plan_id'] . ".jpg";
                            list($x1,$x2) = $assembly['plan_assembly_position_x_axis'];
                            list($y1,$y2) = $assembly['plan_assembly_position_y_axis'];
                            $h = str_replace("px", "", $assembly['plan_assembly_height']);
                            $h = 300;
                            $w = str_replace("px", "", $assembly['plan_assembly_width']);
                            $rotate = rad2deg($assembly['assembly_icon_rotate']);
                            exec('convert  xc: -draw "line '.$x1.','.$y1.','.$x2.','.$y2.'"  -transparent white ' .$tempPath . $assemblyImage);
                            echo 'convert  xc: -draw "line '.$x1.','.$y1.','.$x2.','.$y2.'"  -transparent white ' .$tempPath . $assemblyImage;exit;
                            //echo 'convert -size '.$w.'x'.$h.' xc: -draw "line '.$x1.','.$y1.','.$x2.','.$y2.'" -draw "stroke black fill black translate 70,10 rotate '.$rotate. 'scale 2,1 path \'M 0,0  l -15,-5  +5,+5  -5,+5  +15,-5 z\' " -transparent white ' .$tempPath . $assemblyImage;exit;
                            chmod($tempPath . $assemblyImage, 0777);*/
                            
                        } else if($assembly['assembly_asset_type'] == 'overlay'){
                            $assemblyImage = $assembly['assembly_icon_type']."_overlay.png";
                            
                        }else {
                            if ($assembly['assembly_icon_type'] == 'opaque')
                                $assemblyImage = $assembly['assembly_opaque_icon_path'];
                            else if ($assembly['assembly_icon_type'] == 'override')
                                $assemblyImage = $assembly['assembly_green_icon_path'];
                            else if ($assembly['assembly_icon_type'] == 'transparent')
                                $assemblyImage = $assembly['assembly_transparent_icon_path'];
                            else
                                $assemblyImage = $assembly['assembly_red_icon_path'];
                        }
                        $t = false;
                        //check if rotate option is not = 0 then rotate the assebly first & add to plan image after.
                        //$assembly['assembly_icon_rotate'] = 145;
                        if ($assembly['assembly_icon_rotate'] != '' && $assembly['assembly_icon_rotate'] != 0 && $assembly['assembly_asset_type'] != 'textbox' && $assembly['assembly_asset_type'] != 'line') {
                            $s = $assembly['plan_assembly_plan_id'] . ".png";
                            if ($assembly['assembly_asset_type'] == 'logo')
                                copy($companyLogoPath . $assemblyImage, $tempPath . $s);
                            else if ($assembly['assembly_asset_type'] != 'connected_lines')
                                copy(ASSEMBLY_IMAGE . $assemblyImage, $tempPath . $s);
                            chmod($tempPath . $s, 0777);
                            $assemblyImagePath = $tempPath . $s;
                            exec('convert ' . $assemblyImagePath . ' -resize ' . $assembly['plan_assembly_width'] . 'x' . $assembly['plan_assembly_height'] . ' ' . $assemblyImagePath);
                            exec('convert ' . $assemblyImagePath . ' -set option:distort:viewport ' . $assembly['plan_assembly_width'] . 'x' . $assembly['plan_assembly_height'] . ' -distort ScaleRotateTranslate ' . $assembly['assembly_icon_rotate'] . ' +repage ' . $assemblyImagePath);

                            $t = true;
                        }else {
                            if ($assembly['assembly_asset_type'] == 'logo')
                                $assemblyImagePath = $companyLogoPath . $assemblyImage;
                            else if ($assembly['assembly_asset_type'] == 'connected_lines' || $assembly['assembly_asset_type'] == 'textbox' || $assembly['assembly_asset_type'] == 'line')
                                $assemblyImagePath = $tempPath . $assemblyImage;
                            else if($assembly['assembly_asset_type'] == 'overlay')
                                $assemblyImagePath = './img/'.$assemblyImage;
                            else
                                $assemblyImagePath = ASSEMBLY_IMAGE . $assemblyImage;
                        }
                        if($assembly['assembly_asset_type'] == 'overlay')
                            $tempOverlay .= " $assemblyImagePath -geometry " . $assembly['plan_assembly_width'] . "x" . $assembly['plan_assembly_height'] . "+" . str_ireplace("px", "", $assembly['plan_assembly_position_x_axis']) . "+" . str_ireplace("px", "", $assembly['plan_assembly_position_y_axis'])." -composite \ ";
                        else if($assembly['assembly_asset_type'] == 'line')
                             //$tempI .= " $assemblyImagePath -geometry " . $w . "x" . $h . "+" . str_ireplace("px", "", $x1) . "+" . str_ireplace("px", "", $y1)." -composite \ ";
                           // $tempI .= "  -fill none -stroke blue -draw \"line $x1 , $y1 $x2, $y2 \"";
echo "hi";
                         else
                             $tempI .= " $assemblyImagePath -geometry " . $assembly['plan_assembly_width'] . "x" . $assembly['plan_assembly_height'] . "+" . str_ireplace("px", "", $assembly['plan_assembly_position_x_axis']) . "+" . str_ireplace("px", "", $assembly['plan_assembly_position_y_axis'])." -composite \ ";
                    }
                    $tempI = $tempT." ".$tempOverlay." ".$tempI;
                    $tempI .=" ".$tempPlanImage;                    
                    exec($tempI);
                    /* start to check if project is forcebully stopped then stop the PDF Generation */
                    $t = $this->stopPdfGeneration($projectId,$tempPath,$time);                    
                    if(!$t)
                        return false;
                    /**End **/
                    //exec("composite -geometry " . $assembly['plan_assembly_width'] . "x" . $assembly['plan_assembly_height'] . "+" . str_ireplace("px", "", $assembly['plan_assembly_position_x_axis']) . "+" . str_ireplace("px", "", $assembly['plan_assembly_position_y_axis']) . "  $assemblyImagePath $tempPlanImage $tempPlanImage");
                }else {
                     $images[] = PROJECT_FOLDER . $projectId . "/" . $plan->filename;
                     //$images .= PROJECT_FOLDER . $projectId . "/" . $plan->filename;
                }
            }
            
            @unlink(PROJECT_FOLDER . $projectId . "/updatedplan.pdf");
            //echo $images;exit;
            $destpage = "projects/" . $projectId . "/updatedplan.pdf";
            
            if ($images != "") {
                
                $query = "select * from companies c join projects p on p.project_company_id = c.company_id where project_id=".$projectId;
                $companyInfo = $this->db->query($query)->row();
        
                $this->load->library('Custompdf');
                global $pdf;
                $pdf = new Custompdf(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                $pdf->data['companyInfo'] = $companyInfo;
                $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
                $pdf->SetPrintHeader(TRUE);
                $pdf->SetPrintFooter(TRUE);
                define(PDF_MARGIN_TOP, 210);

                // set margins
                $pdf->SetMargins(PDF_MARGIN_LEFT, 30, PDF_MARGIN_RIGHT);
                //define(PDF_MARGIN_TOP, 200);
                $margin = 90;
                // set margins
                //$pdf->SetMargins(10, 10, 10);
                // set auto page breaks
                $pdf->SetAutoPageBreak(TRUE, 30); //PDF_MARGIN_BOTTOM
                // set image scale factor
                $pdf->setImageScale(1.53);

                // set some language-dependent strings (optional)
                if (@file_exists(dirname(__FILE__) . '/lang/eng.php')) {
                    require_once(dirname(__FILE__) . '/lang/eng.php');
                    $pdf->setLanguageArray($l);
                }

                // ---------------------------------------------------------
                // set font
                //$pdf->SetFont('timesB', '', 10);
                //$pdf->d('fonts-roboto/Roboto-Black.tty');
                $fontname = TCPDF_FONTS::addTTFfont('fonts-roboto/Roboto-Black.ttf', 'TrueTypeUnicode', '', 10);
                $pdf->SetFont('', '', 11, '', false);

                $pdf->AddPage("L","A4");



                $html = '<br /><h1 style="padding-left:80px;text-align:center;font-size:38px;color:red" >Electrical Legend</h1></center><style>#cssTable td {    
    vertical-align:middle;
}</style><table width="100%" id="cssTable" style="padding: 2px;">';
                if (!empty($projectAssemblies)) {
                    $i =1;
                    foreach ($projectAssemblies as $pa) {
                        if ($i%3 == 1)
                            $html .= '<tr>';
                        
                        //$html .='<td width="33%"><img src="' .base_url(). ASSEMBLY_IMAGE . $pa['assembly_opaque_icon_path'] . '" height="30" style="vertical-align:middle" align="middle"><span style="padding-left:5px;padding-bottom:25px;text-align:middle;vertical-align: middle;height:5px">' . $pa['legend'] . "</span></td>";
			$html .='<td width="8%"><img src="' . ASSEMBLY_IMAGE . $pa['result'] . '" height="50"></td><td width="28%">&nbsp;<br/>' . $pa['legend'] . "</td>";
                        if ($i %3 == 0){
                        
                            $html .= "</tr>";
                        
                        }
                        if ($i != count($projectAssemblies)){
                            $i++;                        
                        }
                    }
                    if ($i%3 != 0 && $i == count($projectAssemblies)){
                        $html .= "</tr>";
                    }
                        
                }
                $html .="</table>";
                //echo $html;exit;
                $pdf->writeHTML($html, true, false, false, false, '');
                $pdf->SetAutoPageBreak(FALSE, 30); //PDF_MARGIN_BOTTOM
                $pdf->SetPrintHeader(FALSE);
                $pdf->setMargins(0, 0, 0, true);
                foreach ($images as $im) {
                    $pdf->AddPage("L","A4");
                    $pdf->setJPEGQuality(100);
                    $pdf->Image($im, "C", "6", "", "", 'PNG', '', 'C', true, 300, 'C', false, false, 0, false, false, true);
                }
                $legendPdf = "projects/" . $projectId . "/legend.pdf";
                $legendpng = "projects/" . $projectId . "/legend.png";
                /* start to check if project is forcebully stopped then stop the PDF Generation */
                $t = $this->stopPdfGeneration($projectId,$tempPath,$time);                    
                if(!$t)
                    return false;
                /**End **/
                //$pdf->Output(FCPATH . $legendPdf, 'F');
                $pdf->Output(FCPATH . $destpage, 'F');
                //convert pdf to images 
                //exec("convert -density 300 $legendPdf $legendpng");
                //$images = $legendpng." ".$images;
            }
            //echo 1;exit;
            //exec("convert $images  $destpage");
            @chmod($destpage, 0777);
           $this->removeDirectory($tempPath);
            
            //insert into the projects_files table
            $this->db->insert('project_files', array('projectid' => $projectId, 'type' => 'selected_plan', 'filename' => "updatedplan.pdf"));
            $this->updatePdfStatus($projectId);
            return $destpage;
        } else {
            return PROJECT_FOLDER . $projectId . "/" . $selectedPlan->filename;
        }
    }

    /*
     * Purpose: Get the Plan updated UserName ,role & updated date
     */

    function getPlanUpdateDetails($projectId) {
        //$query = "SELECT u.`user_first_name`,u.`user_last_name`,a.`plan_assembly_created_date` FROM users u JOIN assemblies_on_plan a ON a.`plan_assembly_created_by` = u.`uid` WHERE a.`project_id` = " . $projectId . " ORDER BY plan_assembly_plan_id DESC";
        $query = 'SELECT u.user_first_name,u.user_last_name,project_created_date as project_updated_date  from projects p'
                . ' INNER JOIN users u'
                . ' ON u.uid = p.project_updated_by'
                . ' where p.project_id="'.$projectId.'"'; 
       return $this->db->query($query)->row();

    }

    function stopPdfGeneration($projectId,$tempPath,$time){
        $pd = $t = $this->db->where(array('project_id'=>$projectId))->get('projects')->row();
        if (!empty($pd)){
            if($pd->lastgeneratedPdf != $time){
                $this->removeDirectory($tempPath);            
                return false;
            }
        }
        return true;
    } 
    
    function removeDirectory($path){
        $this->load->helper('file');
        delete_files($path,true);
        rmdir($path);
    }
}