"""Esquemas Pydantic para entrada/salida de la API."""

from datetime import date
from typing import List, Optional

from pydantic import BaseModel, ConfigDict, model_validator

from .models import EstadoDocumento, TipoDocumento


class ChoferCreate(BaseModel):
    """Datos para crear o actualizar un chofer."""

    nombre: str
    apellido: str
    dni: str
    telefono: Optional[str] = None
    email: Optional[str] = None
    activo: bool = True


class CamionCreate(BaseModel):
    """Datos para crear o actualizar un camión."""

    patente: str
    marca: Optional[str] = None
    modelo: Optional[str] = None
    anio: Optional[int] = None
    activo: bool = True


class DocumentoCreate(BaseModel):
    """Datos para crear o actualizar un documento (curso/permiso)."""

    nombre: str
    tipo: TipoDocumento = TipoDocumento.CURSO
    fecha_emision: Optional[date] = None
    fecha_vencimiento: date
    dias_aviso: int = 30
    notas: Optional[str] = None
    chofer_id: Optional[int] = None
    camion_id: Optional[int] = None

    @model_validator(mode="after")
    def validar_titular(self) -> "DocumentoCreate":
        """
        Valida que el documento pertenezca a exactamente un titular.

        Returns:
            DocumentoCreate: la instancia validada.

        Raises:
            ValueError: si no se indica titular o se indican ambos.
        """
        if bool(self.chofer_id) == bool(self.camion_id):
            raise ValueError(
                "El documento debe pertenecer a un chofer O a un camión (exactamente uno)."
            )
        if self.dias_aviso < 0:
            raise ValueError("dias_aviso no puede ser negativo.")
        return self


class DocumentoRead(BaseModel):
    """Documento con su estado de vencimiento calculado."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    nombre: str
    tipo: TipoDocumento
    fecha_emision: Optional[date]
    fecha_vencimiento: date
    dias_aviso: int
    notas: Optional[str]
    chofer_id: Optional[int]
    camion_id: Optional[int]
    estado: EstadoDocumento
    dias_restantes: int
    titular: str


class ChoferRead(BaseModel):
    """Chofer con la cantidad de alertas de sus documentos."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    nombre: str
    apellido: str
    dni: str
    telefono: Optional[str]
    email: Optional[str]
    activo: bool
    documentos: List[DocumentoRead] = []


class CamionRead(BaseModel):
    """Camión con sus documentos."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    patente: str
    marca: Optional[str]
    modelo: Optional[str]
    anio: Optional[int]
    activo: bool
    documentos: List[DocumentoRead] = []
