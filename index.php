<?php

$projectsPaths = [
];

$isPreview = true;
$sendEmails = false;

$fromMail = '';
$fromName = '';
$greeting = '';

// 14 days
$thresholdDuration = 60 * 60 * 24 * 14;

class BranchData
{
    /**
     * @var string
     */
    public $branch;

    /**
     * @var string
     */
    public $subject;

    /**
     * @var int
     */
    public $timestamp;

    /**
     * @var string
     */
    public $hash;

    /**
     * @param string $branch
     * @param string $subject
     * @param string $timestamp
     * @param string $hash
     */
    public function __construct(string $branch, string $subject, string $timestamp, string $hash)
    {
        $this->branch = $branch;
        $this->subject = $subject;
        $this->timestamp = (int)$timestamp;
        $this->hash = $hash;
    }

    /**
     * @return false|string
     */
    public function getDate()
    {
        return date('d.m.Y - H:i:s', $this->timestamp);
    }

    /**
     * @return string
     */
    public function renderBranchEntry()
    {
        return '
            <li class="branch-info">
              <span>' . $this->branch . '</span>
              <span>' . $this->getDate() . '</span>
            </li>
        ';
    }

    /**
     * @return string
     */
    public function renderBranchEmailEntry()
    {
        return '
            <li>
              <span>' . $this->branch . '</span> - <span>' . $this->getDate() . '</span>
            </li>
        ';
    }
}

class UserProjectData
{
    /**
     * @var string
     */
    public $email;

    /**
     * @var string
     */
    public $author;

    /**
     * @var \BranchData[]
     */
    public $branches;

    /**
     * @param string $email
     * @param string $author
     * @param \BranchData[] $branches
     */
    public function __construct(string $email, string $author, array $branches)
    {
        $this->email = $email;
        $this->author = $author;
        $this->branches = $branches;
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->branches);
    }

    /**
     * @return string
     */
    public function renderEmailBranchList()
    {
        $html = '';
        $html .= '<ul>';

        foreach ($this->branches as $branch) {
            $html .= $branch->renderBranchEmailEntry();
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * @return string
     */
    public function render()
    {
        $html = '';
        $html .= '<div class="user-entry">';
        $html .= '<div class="user-entry-info">';
        $html .= '<span class="user-entry-name">' . $this->author . '</span><span class="user-entry-email">' . $this->email . '</span>';
        $html .= '</div>';
        $html .= '<ul class="user-branches">';

        foreach ($this->branches as $branch) {
            $html .= $branch->renderBranchEntry();
        }

        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }
}

class ProjectData
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var \UserProjectData[]
     */
    public $userProjectData;

    /**
     * @param string $name
     * @param \UserProjectData[] $userProjectData
     */
    public function __construct(string $name, array $userProjectData)
    {
        $this->name = $name;
        $this->userProjectData = $userProjectData;
    }

    /**
     * @return int
     */
    public function count()
    {
        $sum = 0;

        foreach ($this->userProjectData as $userProjectData) {
            $sum += $userProjectData->count();
        }

        return $sum;
    }

    /**
     * @return string
     */
    public function render()
    {
        $html = '';

        foreach ($this->userProjectData as $projectData) {
            $html .= $projectData->render();
        }

        return $html;
    }
}

/**
 * @param \ProjectData[] $projectsData
 * @param string $fromMail
 * @param string $fromName
 * @param string $greeting
 * @param bool $isPreview
 */
function sendEmails($projectsData, $fromMail, $fromName, $greeting, $isPreview)
{
    $templateSubject = 'Git branches - %s';

    // author, project, branchList
    $templateBody = "Hallo %s,<br/>
<br/>
kann es sein, dass Du deine git branches in <b>%s</b> die schon in master sind nicht remote gelöscht hast?<br/>
<br/>
Branches:
%s
<br/>
Bitte kontrollieren, danke! :)<br/>
<br/>
Diese E-Mail wurde automatisch erstellt.<br/>
$greeting.";

    $headers = "Content-Type: text/html; charset=UTF-8
MIME-Version: 1.0
From: =?utf-8?b?" . base64_encode($fromName) . "?= <$fromMail>
Bcc: $fromMail
Reply-To: $fromMail
X-Mailer: PHP/" . phpversion();

    foreach ($projectsData as $projectData) {
        $projectName = $projectData->name;

        foreach ($projectData->userProjectData as $userProjectData) {
            $emailTo = $userProjectData->email;
            $author = $userProjectData->author;
            $branchList = $userProjectData->renderEmailBranchList();

            if ($isPreview === true) {
                $emailTo = $fromMail;
            }

            mail(
                $emailTo,
                "=?utf-8?b?" . base64_encode(sprintf($templateSubject, $projectName)) . "?=",
                sprintf($templateBody, $author, $projectName, $branchList),
                $headers
            );
        }
    }
}

/**
 * @param string $branch
 * @param string $formatPlaceholder
 * @return string
 */
function getLastCommitInfo($branch, $formatPlaceholder)
{
    return exec("git log -1 --pretty=format:$formatPlaceholder $branch");
}

/**
 * @return string
 */
function getGitUrl()
{
    $projectUrl = exec('git config --get remote.origin.url');
    $projectUrl = str_replace('.git', '', $projectUrl);
    $projectUrl = str_replace('http://', '', $projectUrl);
    $projectUrl = str_replace('https://', '', $projectUrl);

    return $projectUrl;
}

/**
 * @return string[]
 */
function getUnmergedBranches()
{
    // Check current branch
    $branches = null;
    exec('git branch -r --merged origin/master 2>&1', $branches);

    return $branches;
}

/**
 * @param string[] $projectPaths
 * @param int $thresholdDuration
 * @return \ProjectData[]
 */
function collectProjectsData($projectPaths, $thresholdDuration)
{
    $data = [];

    foreach ($projectPaths as $projectPath) {
        chdir($projectPath);

        $gitName = getGitUrl();
        $branches = getUnmergedBranches();
        $success = is_array($branches);

        if ($success === false) {
            throw new Error("'$gitName' repository not found.");
        }

        $project = [];

        $branches = array_filter($branches, function ($branch) {
            return preg_match('/master/', $branch) === 0
                && preg_match('/stage/', $branch) === 0;
        });

        $branches = array_filter($branches, function ($branch) use ($thresholdDuration) {
            $branch = trim($branch);

            $lastCommitDate = (int)getLastCommitInfo($branch, '%at');
            $threshold = time() - $thresholdDuration;

            return $lastCommitDate <= $threshold;
        });

        foreach ($branches as $branch) {
            $branch = trim($branch);

            $author = getLastCommitInfo($branch, '%an');
            $email = getLastCommitInfo($branch, '%ae');

            $hash = getLastCommitInfo($branch, '%h');
            $date = getLastCommitInfo($branch, '%at');
            $subject = getLastCommitInfo($branch, '%s');

            $branchData = new BranchData($branch, $subject, $date, $hash);

            if (isset($project[$email])) {
                /** @var \UserProjectData $userProjectData */
                $userProjectData = $project[$email];
                $userProjectData->branches[] = $branchData;
            } else {
                $project[$email] = new UserProjectData($email, $author, [$branchData]);
            }
        }

        $data[] = new ProjectData($gitName, $project);
    }

    return $data;
}

$projectsData = collectProjectsData($projectsPaths, $thresholdDuration);

$log = [];
$log[] = '<main>';

// render log
foreach ($projectsData as $projectData) {
    $log[] = '
        <div class="project">
            <h1>' . $projectData->name . '</h1>
            <div class="branch-count">' . $projectData->count() . ' branches</div>
    ';

    foreach ($projectData->userProjectData as $userProjectData) {
        $log[] = $userProjectData->render();
    }

    $log[] = '
        </div>
    ';
}

$log[] = '</main>';

if ($sendEmails === true) {
    sendEmails($projectsData, $fromMail, $fromName, $greeting, $isPreview);
}

?>
<html>
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1">
  <title>Git branches</title>
  <style>
  body {
    font-family: Arial, serif;
    background: #e2e1e0;
  }

  .project {
    margin-bottom: 40px;
  }

  h1 {
    font-size: 22px;
    margin: 0 0 6px;
  }

  .branch-count {
    font-size: 14px;
    margin-bottom: 12px;
  }

  .user-entry {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
    transition: all 0.1s cubic-bezier(.25, .8, .25, 1);
    background-color: white;
    width: 100%;
    margin: 0 auto 20px;
    padding: 10px;
    box-sizing: border-box;
  }

  .user-entry:hover {
    box-shadow: 0 14px 28px rgba(0, 0, 0, 0.25), 0 10px 10px rgba(0, 0, 0, 0.22);
  }

  .user-entry-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    font-weight: bold;
  }

  .user-entry-email {
    font-size: 12px;
  }

  .user-branches {
    list-style: none;
    padding: 0;
    margin: 0;
  }

  .branch-info {
    display: flex;
    justify-content: space-between;
    font-family: 'Consolas', monospace;
    font-size: 14px;
    margin-bottom: 6px;
    transition: background-color .1s ease;
  }

  .branch-info:hover {
    background-color: rgba(0, 0, 0, .15);
  }

  .branch-info:last-of-type {
    margin-bottom: 0;
  }

  main {
    max-width: 800px;
    margin: 0 auto;
  }
  </style>
</head>
<body>
<?php echo implode('', $log); ?>
</body>
</html>
