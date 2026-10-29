<?php
declare(strict_types=1);

namespace Infrastructure\Plugin\Metadata;

use Infrastructure\Plugin\Enum\PluginType;
use Infrastructure\Plugin\Enum\PluginStatus;

/**
 * 插件元数据 / Plugin metadata
 * 
 * 存储插件的基本信息和状态 / Stores basic information and status of a plugin
 * 
 * @package Infrastructure\Plugin\Metadata
 */
class PluginMetadata
{
    /**
     * 插件ID / Plugin ID
     */
    private string $id;
    
    /**
     * 插件名称 / Plugin name
     */
    private string $name;
    
    /**
     * 插件版本 / Plugin version
     */
    private string $version;
    
    /**
     * 插件描述 / Plugin description
     */
    private string $description;
    
    /**
     * 插件作者 / Plugin author
     */
    private string $author;
    
    /**
     * 插件类型 / Plugin type
     */
    private PluginType $type;
    
    /**
     * 插件状态 / Plugin status
     */
    private PluginStatus $status;
    
    /**
     * 插件路径 / Plugin path
     */
    private string $path;
    
    /**
     * 插件入口文件 / Plugin entry file
     */
    private string $entryFile;
    
    /**
     * 插件依赖 / Plugin dependencies
     * 
     * @var array<string, string>
     */
    private array $dependencies;
    
    /**
     * 插件配置 / Plugin configuration
     * 
     * @var array<string, mixed>
     */
    private array $configuration;
    
    /**
     * 安装时间 / Installation time
     */
    private ?\DateTime $installedAt;
    
    /**
     * 更新时间 / Update time
     */
    private ?\DateTime $updatedAt;
    
    /**
     * 构造函数 / Constructor
     * 
     * @param string $id 插件ID / Plugin ID
     * @param string $name 插件名称 / Plugin name
     * @param PluginType $type 插件类型 / Plugin type
     * @param string $path 插件路径 / Plugin path
     */
    public function __construct(string $id, string $name, PluginType $type, string $path)
    {
        $this->id = $id;
        $this->name = $name;
        $this->type = $type;
        $this->path = $path;
        $this->status = PluginStatus::UNINSTALLED;
        $this->dependencies = [];
        $this->configuration = [];
    }
    
    /**
     * 获取插件ID / Get plugin ID
     * 
     * @return string 插件ID / Plugin ID
     */
    public function getId(): string
    {
        return $this->id;
    }
    
    /**
     * 设置插件ID / Set plugin ID
     * 
     * @param string $id 插件ID / Plugin ID
     * @return self
     */
    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }
    
    /**
     * 获取插件名称 / Get plugin name
     * 
     * @return string 插件名称 / Plugin name
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * 设置插件名称 / Set plugin name
     * 
     * @param string $name 插件名称 / Plugin name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
    
    /**
     * 获取插件版本 / Get plugin version
     * 
     * @return string 插件版本 / Plugin version
     */
    public function getVersion(): string
    {
        return $this->version;
    }
    
    /**
     * 设置插件版本 / Set plugin version
     * 
     * @param string $version 插件版本 / Plugin version
     * @return self
     */
    public function setVersion(string $version): self
    {
        $this->version = $version;
        return $this;
    }
    
    /**
     * 获取插件描述 / Get plugin description
     * 
     * @return string 插件描述 / Plugin description
     */
    public function getDescription(): string
    {
        return $this->description;
    }
    
    /**
     * 设置插件描述 / Set plugin description
     * 
     * @param string $description 插件描述 / Plugin description
     * @return self
     */
    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }
    
    /**
     * 获取插件作者 / Get plugin author
     * 
     * @return string 插件作者 / Plugin author
     */
    public function getAuthor(): string
    {
        return $this->author;
    }
    
    /**
     * 设置插件作者 / Set plugin author
     * 
     * @param string $author 插件作者 / Plugin author
     * @return self
     */
    public function setAuthor(string $author): self
    {
        $this->author = $author;
        return $this;
    }
    
    /**
     * 获取插件类型 / Get plugin type
     * 
     * @return PluginType 插件类型 / Plugin type
     */
    public function getType(): PluginType
    {
        return $this->type;
    }
    
    /**
     * 获取插件状态 / Get plugin status
     * 
     * @return PluginStatus 插件状态 / Plugin status
     */
    public function getStatus(): PluginStatus
    {
        return $this->status;
    }
    
    /**
     * 设置插件状态 / Set plugin status
     * 
     * @param PluginStatus $status 插件状态 / Plugin status
     * @return self
     */
    public function setStatus(PluginStatus $status): self
    {
        $this->status = $status;
        return $this;
    }
    
    /**
     * 获取插件路径 / Get plugin path
     * 
     * @return string 插件路径 / Plugin path
     */
    public function getPath(): string
    {
        return $this->path;
    }
    
    /**
     * 获取插件入口文件 / Get plugin entry file
     * 
     * @return string 插件入口文件 / Plugin entry file
     */
    public function getEntryFile(): string
    {
        return $this->entryFile;
    }
    
    /**
     * 设置插件入口文件 / Set plugin entry file
     * 
     * @param string $entryFile 插件入口文件 / Plugin entry file
     * @return self
     */
    public function setEntryFile(string $entryFile): self
    {
        $this->entryFile = $entryFile;
        return $this;
    }
    
    /**
     * 获取插件依赖 / Get plugin dependencies
     * 
     * @return array<string, string> 插件依赖 / Plugin dependencies
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }
    
    /**
     * 设置插件依赖 / Set plugin dependencies
     * 
     * @param array<string, string> $dependencies 插件依赖 / Plugin dependencies
     * @return self
     */
    public function setDependencies(array $dependencies): self
    {
        $this->dependencies = $dependencies;
        return $this;
    }
    
    /**
     * 添加插件依赖 / Add plugin dependency
     * 
     * @param string $pluginId 插件ID / Plugin ID
     * @param string $version 版本要求 / Version requirement
     * @return self
     */
    public function addDependency(string $pluginId, string $version): self
    {
        $this->dependencies[$pluginId] = $version;
        return $this;
    }
    
    /**
     * 获取插件配置 / Get plugin configuration
     * 
     * @return array<string, mixed> 插件配置 / Plugin configuration
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }
    
    /**
     * 设置插件配置 / Set plugin configuration
     * 
     * @param array<string, mixed> $configuration 插件配置 / Plugin configuration
     * @return self
     */
    public function setConfiguration(array $configuration): self
    {
        $this->configuration = $configuration;
        return $this;
    }
    
    /**
     * 获取安装时间 / Get installation time
     * 
     * @return \DateTime|null 安装时间 / Installation time
     */
    public function getInstalledAt(): ?\DateTime
    {
        return $this->installedAt;
    }
    
    /**
     * 设置安装时间 / Set installation time
     * 
     * @param \DateTime|null $installedAt 安装时间 / Installation time
     * @return self
     */
    public function setInstalledAt(?\DateTime $installedAt): self
    {
        $this->installedAt = $installedAt;
        return $this;
    }
    
    /**
     * 获取更新时间 / Get update time
     * 
     * @return \DateTime|null 更新时间 / Update time
     */
    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }
    
    /**
     * 设置更新时间 / Set update time
     * 
     * @param \DateTime|null $updatedAt 更新时间 / Update time
     * @return self
     */
    public function setUpdatedAt(?\DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
    
    /**
     * 转换为数组 / Convert to array
     * 
     * @return array<string, mixed> 数组表示 / Array representation
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'path' => $this->path,
            'entry_file' => $this->entryFile,
            'dependencies' => $this->dependencies,
            'configuration' => $this->configuration,
            'installed_at' => $this->installedAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * 从数组创建元数据 / Create metadata from array
     * 
     * @param array<string, mixed> $data 数组数据 / Array data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $metadata = new self(
            $data['id'],
            $data['name'],
            PluginType::from($data['type']),
            $data['path']
        );
        
        $metadata->version = $data['version'] ?? '';
        $metadata->description = $data['description'] ?? '';
        $metadata->author = $data['author'] ?? '';
        $metadata->status = PluginStatus::from($data['status'] ?? PluginStatus::UNINSTALLED->value);
        $metadata->entryFile = $data['entry_file'] ?? '';
        $metadata->dependencies = $data['dependencies'] ?? [];
        $metadata->configuration = $data['configuration'] ?? [];
        
        if (isset($data['installed_at'])) {
            $metadata->installedAt = new \DateTime($data['installed_at']);
        }
        
        if (isset($data['updated_at'])) {
            $metadata->updatedAt = new \DateTime($data['updated_at']);
        }
        
        return $metadata;
    }
}