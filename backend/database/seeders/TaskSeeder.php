<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Task;
use App\Models\Project;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $projects = Project::all();
        
        $itTasks = [
            // Development & Programming
            [
                'name' => 'Frontend Development',
                'description' => 'Desenvolvimento de interfaces de utilizador responsivas utilizando React/Angular/Vue',
                'task_type' => 'installation'
            ],
            [
                'name' => 'Backend API Development', 
                'description' => 'Desenvolvimento de APIs RESTful e microserviços',
                'task_type' => 'installation'
            ],
            [
                'name' => 'Database Design & Optimization',
                'description' => 'Modelagem de base de dados, otimização de queries e performance tuning',
                'task_type' => 'commissioning'
            ],
            [
                'name' => 'Code Review & Quality Assurance',
                'description' => 'Revisão de código, análise de qualidade e implementação de boas práticas',
                'task_type' => 'inspection'
            ],
            
            // Infrastructure & DevOps
            [
                'name' => 'Server Configuration & Setup',
                'description' => 'Configuração de servidores Linux/Windows, instalação de dependências',
                'task_type' => 'installation'
            ],
            [
                'name' => 'CI/CD Pipeline Implementation',
                'description' => 'Configuração de pipelines de integração e deployment contínuo',
                'task_type' => 'commissioning'
            ],
            [
                'name' => 'Docker Containerization',
                'description' => 'Criação e otimização de containers Docker para aplicações',
                'task_type' => 'installation'
            ],
            [
                'name' => 'Cloud Infrastructure Management',
                'description' => 'Gestão de recursos AWS/Azure/GCP, autoscaling e monitoring',
                'task_type' => 'maintenance'
            ],
            
            // Security & Monitoring
            [
                'name' => 'Security Assessment & Penetration Testing',
                'description' => 'Avaliação de vulnerabilidades e testes de penetração',
                'task_type' => 'testing'
            ],
            [
                'name' => 'System Monitoring & Alerting Setup',
                'description' => 'Implementação de sistemas de monitorização (Prometheus, Grafana)',
                'task_type' => 'commissioning'
            ],
            [
                'name' => 'Backup & Disaster Recovery Planning',
                'description' => 'Estratégias de backup automatizado e planos de recuperação',
                'task_type' => 'maintenance'
            ],
            
            // Network & Hardware
            [
                'name' => 'Network Equipment Installation',
                'description' => 'Instalação e configuração de switches, routers e firewalls',
                'task_type' => 'installation'
            ],
            [
                'name' => 'Network Performance Analysis',
                'description' => 'Análise de performance de rede, identificação de bottlenecks',
                'task_type' => 'testing'
            ],
            [
                'name' => 'Hardware Maintenance & Upgrade',
                'description' => 'Manutenção preventiva e upgrades de hardware de servidores',
                'task_type' => 'maintenance'
            ],
            
            // Documentation & Training
            [
                'name' => 'Technical Documentation Creation',
                'description' => 'Criação de documentação técnica, manuais de utilizador e API docs',
                'task_type' => 'documentation'
            ],
            [
                'name' => 'Team Training & Knowledge Transfer',
                'description' => 'Formação de equipas em novas tecnologias e processos',
                'task_type' => 'training'
            ],
            [
                'name' => 'System Architecture Documentation',
                'description' => 'Documentação de arquiteturas de sistema e diagramas técnicos',
                'task_type' => 'documentation'
            ],
            
            // Testing & Validation
            [
                'name' => 'Automated Testing Implementation',
                'description' => 'Desenvolvimento de testes unitários, integração e end-to-end',
                'task_type' => 'testing'
            ],
            [
                'name' => 'Performance Load Testing',
                'description' => 'Testes de carga e performance de aplicações e sistemas',
                'task_type' => 'testing'
            ],
            [
                'name' => 'System Integration Testing',
                'description' => 'Validação de integração entre sistemas e APIs',
                'task_type' => 'testing'
            ],
            
            // Maintenance & Support
            [
                'name' => 'Production System Maintenance',
                'description' => 'Manutenção de sistemas em produção, patches e updates',
                'task_type' => 'maintenance'
            ],
            [
                'name' => 'Incident Response & Troubleshooting',
                'description' => 'Resposta a incidentes, debugging e resolução de problemas',
                'task_type' => 'maintenance'
            ],
            [
                'name' => 'Legacy System Retrofit',
                'description' => 'Modernização e retrofit de sistemas legados',
                'task_type' => 'retrofit'
            ]
        ];
        
        // Create tasks for each project
        foreach ($projects as $project) {
            // Randomly assign 5-8 tasks per project
            $selectedTasks = collect($itTasks)->random(rand(5, 8));
            
            foreach ($selectedTasks as $taskData) {
                Task::create([
                    'project_id' => $project->id,
                    'name' => $taskData['name'],
                    'description' => $taskData['description'],
                    'task_type' => $taskData['task_type'],
                    'is_active' => rand(1, 10) > 2 // 80% active
                ]);
            }
        }
        
        $this->command->info('✅ Created ' . Task::count() . ' IT tasks across ' . $projects->count() . ' projects');
    }
}