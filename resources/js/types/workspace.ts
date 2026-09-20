export type Workspace = {
    id: number;
    name: string;
};

export type WorkspaceContext = {
    current: Workspace | null;
};
